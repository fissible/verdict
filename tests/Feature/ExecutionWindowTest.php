<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\ExecutionWindow;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\VerdictManager;

/**
 * The execution window wraps exactly the executor invocation — the decided seam for predicate
 * capture (#251). Verdict's own store traffic (evidence, approval receipts, execution claims,
 * rate limits) runs outside it by construction, so a captured statement is the executor's, never
 * the boundary's bookkeeping: the presence assertion must not be satisfiable by harness-owned SQL.
 */
final class RecordingExecutionWindow implements ExecutionWindow
{
    /** @var list<string> */
    public array $capabilities = [];

    public function around(ActionEnvelope $envelope, callable $execution): mixed
    {
        $this->capabilities[] = $envelope->proposal->capability;

        return $execution();
    }
}

function executionWindowCapability(string $name, int &$executorCalls): Capability
{
    return Capability::usingPolicy(
        name: $name,
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): string => 'order-1001',
    )->executionTarget(acceptTestSnapshot('execution-window-snapshot-'.$name))
        ->executeUsing(function (AuthorizedAction $action) use (&$executorCalls): string {
            $executorCalls++;

            return 'executed';
        });
}

it('wraps the executor invocation in the bound execution window', function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });

    $window = new RecordingExecutionWindow;
    $this->app->instance(ExecutionWindow::class, $window);

    $executorCalls = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(executionWindowCapability('orders.window-permit', $executorCalls));

    $result = $verdict->runBound(
        ActionEnvelope::wrap(new ActionProposal('orders.window-permit', ['order_id' => 1001]), new ActionContext('customer-72')),
    );

    expect($result->executed)->toBeTrue()
        ->and($result->output)->toBe('executed')
        ->and($executorCalls)->toBe(1)
        ->and($window->capabilities)->toBe(['orders.window-permit']);
});

it('never opens the window when the decision denies execution', function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::deny('Authority does not permit this action.');
        }
    });

    $window = new RecordingExecutionWindow;
    $this->app->instance(ExecutionWindow::class, $window);

    $executorCalls = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(executionWindowCapability('orders.window-deny', $executorCalls));

    $result = $verdict->runBound(
        ActionEnvelope::wrap(new ActionProposal('orders.window-deny', ['order_id' => 1001]), new ActionContext('customer-72')),
    );

    expect($result->executed)->toBeFalse()
        ->and($executorCalls)->toBe(0)
        ->and($window->capabilities)->toBe([]);
});

it('executes directly when no window is bound', function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });

    $executorCalls = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(executionWindowCapability('orders.window-unbound', $executorCalls));

    $result = $verdict->runBound(
        ActionEnvelope::wrap(new ActionProposal('orders.window-unbound', ['order_id' => 1001]), new ActionContext('customer-72')),
    );

    expect($result->executed)->toBeTrue()
        ->and($executorCalls)->toBe(1);
});
