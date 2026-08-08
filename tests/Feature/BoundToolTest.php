<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Exceptions\CapabilityNotExecutable;
use Fissible\Verdict\Exceptions\UnknownCapability;
use Fissible\Verdict\LaravelAi\BoundTool;
use Fissible\Verdict\Policies\LaravelPolicyAuthorizer;
use Fissible\Verdict\VerdictManager;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate as GateFacade;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class BoundCustomer implements AuthenticatableContract
{
    use Authenticatable;

    public function __construct(public int $id) {}
}

final class BoundOrder
{
    public function __construct(public int $id, public int $customerId) {}
}

final class BoundOrderPolicy
{
    public function view(BoundCustomer $customer, BoundOrder $order): Response
    {
        return $customer->id === $order->customerId
            ? Response::allow()
            : Response::deny("Order belongs to customer {$order->customerId}.");
    }
}

final class DefinitionOnlyOrderTool implements Tool
{
    public int $invocations = 0;

    public function description(): Stringable|string
    {
        return 'Look up an order by ID.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->invocations++;

        return 'The raw tool handler must not run.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class RevokeAfterFirstAuthorization implements CapabilityAuthorizer
{
    public int $calls = 0;

    public function __construct(private readonly CapabilityAuthorizer $authorizer) {}

    public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
    {
        $this->calls++;
        $decision = $this->authorizer->decide($capability, $envelope, $target);

        if ($this->calls === 1 && $target instanceof BoundOrder) {
            $target->customerId = 91;
        }

        return $decision;
    }
}

beforeEach(function (): void {
    GateFacade::policy(BoundOrder::class, BoundOrderPolicy::class);
});

/**
 * @param  array<int, BoundOrder>  $orders
 * @param  callable(AuthorizedAction): string  $executor
 */
function boundOrderCapability(array $orders, callable $executor, ?int &$resolutions = null): Capability
{
    return Capability::usingPolicy(
        name: 'orders.bound-view',
        ability: 'view',
        resolveTarget: function (ActionEnvelope $envelope) use ($orders, &$resolutions): BoundOrder {
            $resolutions ??= 0;
            $resolutions++;

            return $orders[$envelope->proposal->arguments['order_id']];
        },
    )->executionTarget(acceptTestSnapshot('bound-order-snapshot'))->executeUsing($executor);
}

it('executes with the exact canonical target and never calls the definition handler', function (): void {
    $orders = [1001 => new BoundOrder(1001, 72)];
    $definition = new DefinitionOnlyOrderTool;
    $executedTarget = null;
    $resolutions = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(boundOrderCapability(
        $orders,
        function (AuthorizedAction $action) use (&$executedTarget): string {
            $executedTarget = $action->target;

            if (! $action->target instanceof BoundOrder) {
                throw new LogicException('Expected a bound order target.');
            }

            return json_encode(['id' => $action->target->id], JSON_THROW_ON_ERROR);
        },
        $resolutions,
    ));

    $tool = $verdict->bound(
        $definition,
        'orders.bound-view',
        new ActionContext(new BoundCustomer(72)),
    );
    $result = json_decode((string) $tool->handle(new Request(['order_id' => 1001])), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toBe(['id' => 1001])
        ->and($executedTarget)->toBe($orders[1001])
        ->and($resolutions)->toBe(1)
        ->and($definition->invocations)->toBe(0);

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        throw new LogicException('Expected the in-memory evidence recorder.');
    }

    expect(array_column($recorder->all(), 'stage'))->toBe(['proposal', 'target_refresh', 'execution'])
        ->and(array_column($recorder->all(), 'disposition'))->toBe(['permit', 'permit', 'permit']);
});

it('re-authorizes the same target and stops execution when authority changes', function (): void {
    $order = new BoundOrder(1001, 72);
    $definition = new DefinitionOnlyOrderTool;
    $executorCalls = 0;
    $authorizer = new RevokeAfterFirstAuthorization(
        new LaravelPolicyAuthorizer(app(Gate::class)),
    );
    $this->app->instance(CapabilityAuthorizer::class, $authorizer);

    $verdict = app(VerdictManager::class);
    $verdict->capability(boundOrderCapability(
        [1001 => $order],
        function (AuthorizedAction $action) use (&$executorCalls): string {
            $executorCalls++;

            return 'executed';
        },
    ));

    $tool = $verdict->bound(
        $definition,
        'orders.bound-view',
        new ActionContext(new BoundCustomer(72)),
    );
    $result = json_decode((string) $tool->handle(new Request(['order_id' => 1001])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['decision'])->toBe('deny')
        ->and($authorizer->calls)->toBe(2)
        ->and($executorCalls)->toBe(0)
        ->and($definition->invocations)->toBe(0);

    $recorder = app(EvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        throw new LogicException('Expected the in-memory evidence recorder.');
    }

    expect(array_column($recorder->all(), 'stage'))->toBe(['proposal', 'target_refresh', 'execution'])
        ->and(array_column($recorder->all(), 'disposition'))->toBe(['permit', 'permit', 'deny']);
});

it('rejects a bound capability with no executor at wiring time', function (): void {
    $order = new BoundOrder(1001, 72);
    $definition = new DefinitionOnlyOrderTool;
    $verdict = app(VerdictManager::class);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.bound-view',
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): BoundOrder => $order,
    ));

    expect(fn (): BoundTool => $verdict->bound(
        $definition,
        'orders.bound-view',
        new ActionContext(new BoundCustomer(72)),
    ))->toThrow(
        CapabilityNotExecutable::class,
        'orders.bound-view',
    );

    $recorder = app(EvidenceRecorder::class);

    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        throw new LogicException('Expected the in-memory evidence recorder.');
    }

    expect($recorder->all())->toBe([])
        ->and($definition->invocations)->toBe(0);
});

it('rejects a bound capability that is not registered', function (): void {
    $definition = new DefinitionOnlyOrderTool;
    $verdict = app(VerdictManager::class);

    expect(fn (): BoundTool => $verdict->bound(
        $definition,
        'orders.missing',
        new ActionContext(new BoundCustomer(72)),
    ))->toThrow(
        UnknownCapability::class,
        'orders.missing',
    );
});
