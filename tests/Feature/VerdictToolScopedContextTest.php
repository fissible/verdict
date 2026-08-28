<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\LaravelAi\AbstractVerdictTool;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * #358, Site 2 — AbstractVerdictTool captured the scoped InvocationContext, ApprovalExecutionContext,
 * AND the scoped VerdictManager (as `verdict`) at construction. A tool built once (e.g. at boot, put
 * in an agent's tool list) and reused across Octane requests keeps all three from the discarded
 * boot scope after Container::forgetScopedInstances(), so its per-invocation correlation — including
 * the invocation id the captured manager writes into evidence — belongs to the wrong request.
 *
 * Two tests, per the two-model review: a white-box one that the tool's own context accessors follow
 * the scope, and — the load-bearing one — a behavioral one proving the EXECUTION path (which runs
 * through the captured manager) records the current invocation, not the stale one.
 */
final class ScopedContextProbeTool implements Tool
{
    public function description(): string
    {
        return 'a probe tool';
    }

    public function handle(Request $request): string
    {
        return 'ok';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

beforeEach(function (): void {
    // Permit everything so the execution path runs and records evidence.
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('Permitted.');
        }
    });

    app(VerdictManager::class)->capability(Capability::usingPolicy(
        name: 'probe.run',
        ability: 'run',
        resolveTarget: fn (ActionEnvelope $envelope): string => 'the-target',
    ));
});

function toolContextAccessor(string $method): ReflectionMethod
{
    $accessor = new ReflectionMethod(AbstractVerdictTool::class, $method);
    $accessor->setAccessible(true);

    return $accessor;
}

it('resolves the current scoped contexts after a scope reset, not the ones captured at construction (#358 Site 2)', function (): void {
    $tool = app(VerdictManager::class)->guard(new ScopedContextProbeTool, 'probe.run', new ActionContext('actor-1'));

    $invocations = toolContextAccessor('invocations');
    $approvalExecutions = toolContextAccessor('approvalExecutions');

    $bootInvocation = app(InvocationContext::class);
    $bootApproval = app(ApprovalExecutionContext::class);

    expect($invocations->invoke($tool))->toBe($bootInvocation)
        ->and($approvalExecutions->invoke($tool))->toBe($bootApproval);

    app()->forgetScopedInstances();

    $requestInvocation = app(InvocationContext::class);
    $requestApproval = app(ApprovalExecutionContext::class);

    expect($requestInvocation)->not->toBe($bootInvocation)
        ->and($requestApproval)->not->toBe($bootApproval)
        ->and($invocations->invoke($tool))->toBe($requestInvocation)
        ->and($approvalExecutions->invoke($tool))->toBe($requestApproval);
});

it('records the current request invocation, not the boot one, when a boot-built tool executes after a reset (#358 Site 2)', function (): void {
    // Build the tool "at boot", inside a boot invocation scope.
    app(InvocationContext::class)->push('invocation-boot');
    $tool = app(VerdictManager::class)->guard(new ScopedContextProbeTool, 'probe.run', new ActionContext('actor-1'));

    // Octane discards scoped instances between requests; the tool object is reused.
    app()->forgetScopedInstances();

    // The new request runs under a different invocation scope.
    app(InvocationContext::class)->push('invocation-request');

    $tool->handle(new Request(['id' => 1], 'call-1'));

    // The recorded correlation must be THIS request's invocation — not the boot invocation the tool
    // and its captured manager were constructed under. The container resolves EvidenceRecorder to the
    // singleton InMemoryEvidenceRecorder the manager records to (Larastan infers the concrete type).
    $recorder = app(EvidenceRecorder::class);

    expect($recorder->latest()?->invocationId)->toBe('invocation-request');
});
