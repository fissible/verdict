<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Fissible\Verdict\Evaluation\LiveToolCapture;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;

/**
 * Feeds `ActionLog` writes made during a live tool call into `LiveToolCapture::recordSideEffect()`.
 *
 * `LiveToolCapture::recordSideEffect()` (src/Evaluation/LiveToolCapture.php:22) had no caller
 * anywhere in `src/` or `workbench/` before this class — `Observation::sideEffects` was
 * unconditionally `[]` on every live run, which made `Assertions::noSideEffects()` unfailable and
 * `Assertions::sideEffectOccurred()` unpassable live. `StorefrontScenarioRunner::cancelSideEffectsSince()`
 * does the equivalent diff-and-record for the deterministic runner, including the
 * `"{capability}.executed"` string format (matching `StorefrontAttackPack::mutationSideEffect()`);
 * this mirrors that convention for the live one.
 *
 * Sits between `CapturingTool` and the bound Verdict tool
 * (`CapturingTool(new SideEffectRelayTool($boundTool, ...), ...)`), so the before/after `ActionLog`
 * diff wraps exactly the real tool execution — `ActionLog::record()` happens synchronously inside
 * `AuthorizedAction`'s `executeUsing` closure in `WorkbenchServiceProvider::boot()`, during
 * `$inner->handle()`. Diffing anywhere in `LiveAgentObserver` or the `agentInvoker` closure would
 * be too late or too early: tool execution (and therefore any `ActionLog` write) only happens
 * during `StreamableAgentResponse` iteration, which the observer — not the workbench — performs.
 */
final class SideEffectRelayTool implements Approvable, Tool
{
    public function __construct(
        private readonly Approvable&Tool $inner,
        private readonly ActionLog $actions,
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
        $writesBefore = count($this->actions->all());
        $result = $this->inner->handle($request);

        foreach (array_slice($this->actions->all(), $writesBefore) as $action) {
            $this->capture->recordSideEffect("{$action['capability']}.executed");
        }

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
