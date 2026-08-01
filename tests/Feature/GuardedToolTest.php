<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\LaravelAi\GuardedTool;
use Fissible\Verdict\VerdictManager;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate as GateFacade;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class TestCustomer implements AuthenticatableContract
{
    use Authenticatable;

    public function __construct(public int $id) {}
}

final readonly class TestOrder
{
    public function __construct(public int $id, public int $customerId) {}
}

final class TestOrderPolicy
{
    public function view(TestCustomer $customer, TestOrder $order): Response
    {
        return $customer->id === $order->customerId
            ? Response::allow()
            : Response::deny("Order belongs to customer {$order->customerId}.");
    }
}

class LookupOrderTool implements Tool
{
    public int $invocations = 0;

    /**
     * @param  array<int, TestOrder>  $orders
     */
    public function __construct(private readonly array $orders) {}

    public function description(): Stringable|string
    {
        return 'Look up an order by ID.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->invocations++;

        $order = $this->orders[$request->integer('order_id')];

        return json_encode([
            'id' => $order->id,
            'customer_id' => $order->customerId,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class ApprovalLookupOrderTool extends LookupOrderTool implements Approvable
{
    use InteractsWithApprovals;
}

beforeEach(function (): void {
    GateFacade::policy(TestOrder::class, TestOrderPolicy::class);
});

/**
 * @param  array<int, TestOrder>  $orders
 */
function registerOrderLookupCapability(VerdictManager $verdict, array $orders): void
{
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.view',
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): TestOrder => $orders[$envelope->proposal->arguments['order_id']],
    ));
}

it('denies an idor proposal before the underlying Laravel AI tool executes', function (): void {
    $orders = [
        1001 => new TestOrder(1001, 72),
        1002 => new TestOrder(1002, 91),
    ];
    $customer = new TestCustomer(72);
    $tool = new LookupOrderTool($orders);
    $verdict = app(VerdictManager::class);
    registerOrderLookupCapability($verdict, $orders);

    $guarded = $verdict->guard($tool, 'orders.view', new ActionContext($customer));
    $result = json_decode((string) $guarded->handle(new Request(
        ['order_id' => 1002],
        'call-order-1002',
    )), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toMatchArray([
        'status' => 'not_executed',
        'capability' => 'orders.view',
        'decision' => 'deny',
        'message' => 'This action was not authorized.',
    ])->and($tool->invocations)->toBe(0);

    $recorder = app(EvidenceRecorder::class);

    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    /** @var InMemoryEvidenceRecorder $recorder */
    $evidence = $recorder->latest();

    expect($evidence)->not->toBeNull()
        ->and($evidence?->capability)->toBe('orders.view')
        ->and($evidence?->disposition)->toBe('deny')
        ->and($evidence?->reason)->toBe('Order belongs to customer 91.')
        ->and($evidence?->idempotencyKey)->toBe('call-order-1002')
        ->and($evidence?->argumentFingerprint)->toHaveLength(64);
});

it('executes the underlying tool after the policy permits the bound actor and resource', function (): void {
    $orders = [1001 => new TestOrder(1001, 72)];
    $tool = new LookupOrderTool($orders);
    $verdict = app(VerdictManager::class);
    registerOrderLookupCapability($verdict, $orders);

    $guarded = $verdict->guard($tool, 'orders.view', new ActionContext(new TestCustomer(72)));
    $result = json_decode((string) $guarded->handle(new Request(['order_id' => 1001])), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toBe(['id' => 1001, 'customer_id' => 72])
        ->and($tool->invocations)->toBe(1);
});

it('fails closed when a tool references an unregistered capability', function (): void {
    $tool = new LookupOrderTool([1001 => new TestOrder(1001, 72)]);
    $guarded = app(VerdictManager::class)->guard(
        $tool,
        'orders.unregistered',
        new ActionContext(new TestCustomer(72)),
    );

    $result = json_decode((string) $guarded->handle(new Request(['order_id' => 1001])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['decision'])->toBe('deny')
        ->and($tool->invocations)->toBe(0);
});

it('rejects an invalid server context without invoking the tool', function (): void {
    $tool = new LookupOrderTool([1001 => new TestOrder(1001, 72)]);
    $guarded = new GuardedTool(
        tool: $tool,
        capability: 'orders.view',
        context: fn (Request $request): null => null,
        verdict: app(VerdictManager::class),
    );

    expect(fn (): Stringable|string => $guarded->handle(new Request(['order_id' => 1001])))
        ->toThrow(LogicException::class, 'must return an ActionContext')
        ->and($tool->invocations)->toBe(0);
});

it('preserves an underlying Laravel AI approval requirement', function (): void {
    $tool = (new ApprovalLookupOrderTool([]))->requireApproval('Customer confirmation is required.');
    $guarded = app(VerdictManager::class)->guard(
        $tool,
        'orders.view',
        new ActionContext(new TestCustomer(72)),
    );

    $approval = $guarded->shouldRequestApproval(new Request);

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval?->reason)->toBe('Customer confirmation is required.');
});

it('resolves the Laravel Gate contract from the package container', function (): void {
    expect(app(Gate::class))->toBeInstanceOf(Gate::class)
        ->and(app(VerdictManager::class))->toBe(app(VerdictManager::class));
});
