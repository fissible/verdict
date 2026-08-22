<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;

/**
 * The control arm's counterpart to {@see CapturingTool}: wraps a **plain, unguarded** tool so the
 * observer can see which capability the model invoked and that it executed — with `disposition`
 * deliberately `null`, because no Verdict decision exists on an unguarded path. A non-null
 * disposition in a control observation is precisely what `LiveEvaluationRunner` refuses as an
 * accidentally guarded arm.
 *
 * Deliberately implements only `Tool`, not `Approvable`: `TextGenerationLoop::approvalForTool()`
 * skips non-`Approvable` tools, so no approval preflight applies — which is what "unguarded"
 * means. The mirrored intersection type on `CapturingTool` exists for the opposite reason.
 *
 * See [ADR 0023](../../docs/adr/0023-unguarded-control-arm-pairing-and-opt-in.md).
 */
final class UnguardedCapturingTool implements Tool
{
    /**
     * `$predicates` is the control arm's execution window (#251 round 5): the unguarded path has
     * no `VerdictManager` to open one, so this wrapper — which every control tool already passes
     * through — opens it around the inner handler, with an attribution envelope built from the
     * tool call itself. Wired here at the harness level rather than per tool, so a control tool
     * cannot forget to opt in; without it a filtered-permit case's control arm captures no
     * predicates and every trial is structurally unmeasurable. Construct the capture with the
     * run's `LiveToolCapture` as its sink and captured predicates flow into the observation
     * exactly as the guarded arm's do.
     */
    public function __construct(
        private readonly Tool $inner,
        private readonly string $capability,
        private readonly LiveToolCapture $capture,
        private readonly ?ConnectionPredicateCapture $predicates = null,
    ) {}

    /**
     * The inner tool's name, exactly as {@see CapturingTool} resolves it — the model must see the
     * same tool surface in both arms, or the comparison measured a different suite.
     */
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
        // Recorded after the inner handler returns: a tool that threw before doing its work did
        // not execute, and recording it as executed would report a breach that never happened.
        $result = null;
        $run = function () use (&$result, $request): Stringable|string {
            return $result = $this->inner->handle($request);
        };

        if ($this->predicates === null) {
            $run();
        } else {
            $this->predicates->around(
                ActionEnvelope::wrap(
                    new ActionProposal($this->capability, $request->all()),
                    // No trusted actor exists on an unguarded path — which is the point; the
                    // envelope exists for attribution, not authorization.
                    new ActionContext('control-arm'),
                ),
                $run,
            );
        }

        if ($result === null) {
            throw LiveObservationUnavailable::because('the execution window did not run the control tool');
        }

        $this->capture->record(
            capability: $this->capability,
            argumentFingerprint: ArgumentFingerprint::make($request->all()),
            disposition: null,
            executed: true,
        );

        return $result;
    }
}
