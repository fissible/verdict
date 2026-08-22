<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\LaravelAi\InvocationContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;

/**
 * Wraps a bound Verdict tool (typically `BoundTool`) so a live evaluation run can observe which
 * capability the model invoked, the resulting disposition, and whether execution actually ran —
 * without altering what the model or the approval preflight sees.
 *
 * The `Approvable&Tool` intersection type on `$inner` is deliberate, not decorative:
 * `TextGenerationLoop::approvalForTool()` only calls `shouldRequestApproval()` when
 * `$tool instanceof Approvable`. If this decorator implemented only `Tool`, every
 * confirmation-required case would silently run with no approval preflight while still appearing
 * to take the real Verdict path.
 *
 * The decorator also observes the preflight itself: when the inner tool's `shouldRequestApproval()`
 * pauses, it looks up the challenge that pause must have issued and records it. ADR 0029 decision 3
 * treats a pause with no findable challenge as the instrument going blind, not as "no challenge was
 * issued" — see `shouldRequestApproval()` below.
 *
 * **This decorator assumes a single-shot run, and does not yet support a resumed one.** On
 * laravel/ai's resume path, `shouldRequestApproval()` is re-invoked for the pending call. By then
 * the receipt has been answered, so it is Approved rather than Pending;
 * `ApprovalManager::challengeForToolCall()` reports only Pending receipts and returns null; and the
 * preflight's integrity branch therefore throws `LiveObservationUnavailable`. A correctly resumed
 * run would read as the harness going blind — the loudest possible false negative, on the one path
 * that is supposed to be the good outcome. Before answer-and-resume can use this decorator it must
 * either dedup by tool-call id (a call already observed at its first preflight is not observed
 * again) or handle non-Pending receipts explicitly. Documented rather than fixed: nothing resumes
 * today, and guessing at the resume semantics before that harness exists would pin the wrong shape.
 * See ADR 0029.
 */
final class CapturingTool implements Approvable, Tool
{
    public function __construct(
        private readonly Approvable&Tool $inner,
        private readonly string $capability,
        private readonly LiveToolCapture $capture,
        private readonly ApprovalManager $approvals,
        private readonly InvocationContext $invocations,
    ) {}

    public function name(): string
    {
        return ToolNameResolver::resolve($this->inner);
    }

    public function description(): Stringable|string
    {
        return $this->inner->description();
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->inner->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        $result = $this->inner->handle($request);
        $decoded = json_decode((string) $result, true);
        $notExecuted = is_array($decoded) && ($decoded['status'] ?? null) === 'not_executed';

        if ($notExecuted && ! is_string($decoded['decision'] ?? null)) {
            throw LiveObservationUnavailable::because('a bound tool returned a decision envelope with no decision');
        }

        $this->capture->record(
            capability: $this->capability,
            argumentFingerprint: $this->fingerprint($request),
            disposition: $notExecuted
                ? Disposition::tryFrom($decoded['decision']) ?? throw LiveObservationUnavailable::because(
                    "a bound tool returned an unrecognized decision [{$decoded['decision']}]",
                )
                : Disposition::Permit,
            executed: ! $notExecuted,
        );

        return $result;
    }

    public function requireApproval(?string $reason = null): static
    {
        $this->inner->requireApproval($reason);

        return $this;
    }

    public function withoutApproval(): static
    {
        $this->inner->withoutApproval();

        return $this;
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        $approval = $this->inner->shouldRequestApproval($request);

        if ($approval === null) {
            return null;
        }

        $invocationId = $this->invocations->current();

        if ($invocationId !== null) {
            $this->capture->recordInvocationId($invocationId);
        }

        // ADR 0029 decision 3: a pause with no findable challenge is the instrument going
        // blind — ambiguous lookup, replay, or a framework-level approval that bypasses
        // Verdict — never a measured "no challenge was issued".
        $challenge = $this->approvals->challengeForToolCall((string) $request->toolCallId());

        if ($challenge === null || $challenge->provenance === null) {
            throw LiveObservationUnavailable::because(
                "the approval preflight paused [{$this->capability}] but no observable challenge backs it",
            );
        }

        $this->capture->recordChallenge(ChallengeObservation::fromChallenge($challenge));
        $this->capture->recordPreflightAttempt(
            capability: $this->capability,
            argumentFingerprint: $this->fingerprint($request),
            disposition: Disposition::RequireConfirmation,
            executed: false,
        );

        return $approval;
    }

    private function fingerprint(Request $request): string
    {
        return ArgumentFingerprint::make($request->all());
    }
}
