<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
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
 */
final class CapturingTool implements Approvable, Tool
{
    public function __construct(
        private readonly Approvable&Tool $inner,
        private readonly string $capability,
        private readonly LiveToolCapture $capture,
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
            argumentFingerprint: ArgumentFingerprint::make($request->all()),
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
        return $this->inner->shouldRequestApproval($request);
    }
}
