<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\VerdictManager;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate as GateFacade;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class ToolKindCustomer implements AuthenticatableContract
{
    use Authenticatable;

    public function __construct(public int $id) {}
}

final readonly class ToolKindOrder
{
    public function __construct(public int $id, public int $customerId) {}
}

final class ToolKindOrderPolicy
{
    public function view(ToolKindCustomer $customer, ToolKindOrder $order): Response
    {
        return $customer->id === $order->customerId
            ? Response::allow()
            : Response::deny("Order belongs to customer {$order->customerId}.");
    }
}

final class ToolKindLookupOrderTool implements Tool
{
    /**
     * @param  array<int, ToolKindOrder>  $orders
     */
    public function __construct(private readonly array $orders) {}

    public function description(): Stringable|string
    {
        return 'Look up an order by ID.';
    }

    public function handle(Request $request): Stringable|string
    {
        $order = $this->orders[$request->integer('order_id')];

        return json_encode(['id' => $order->id], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

beforeEach(function (): void {
    GateFacade::policy(ToolKindOrder::class, ToolKindOrderPolicy::class);
});

/**
 * @param  array<int, ToolKindOrder>  $orders
 */
function toolKindOrderCapability(string $name, array $orders): Capability
{
    return Capability::usingPolicy(
        name: $name,
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): ToolKindOrder => $orders[$envelope->proposal->arguments['order_id']],
    )->executionTarget(acceptTestSnapshot('tool-kind-order-snapshot'))
        ->executeUsing(fn (AuthorizedAction $action): string => json_encode(
            ['id' => $action->target instanceof ToolKindOrder ? $action->target->id : null],
            JSON_THROW_ON_ERROR,
        ));
}

/** @return list<InMemoryEvidenceRecorder> stand-in for a fluent assertion helper */
function inMemoryEvidenceRecorder(): InMemoryEvidenceRecorder
{
    $recorder = app(EvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        throw new LogicException('Expected the in-memory evidence recorder.');
    }

    return $recorder;
}

it('tags every evaluation recorded via GuardedTool with the guarded tool kind', function (): void {
    $orders = [1001 => new ToolKindOrder(1001, 72)];
    $verdict = app(VerdictManager::class);
    $verdict->capability(toolKindOrderCapability('orders.tool-kind-guarded', $orders));

    $guarded = $verdict->guard(
        new ToolKindLookupOrderTool($orders),
        'orders.tool-kind-guarded',
        new ActionContext(new ToolKindCustomer(72)),
    );
    $guarded->handle(new Request(['order_id' => 1001]));

    $recorded = inMemoryEvidenceRecorder()->all();

    expect($recorded)->not->toBeEmpty()
        ->and(array_unique(array_column($recorded, 'toolKind')))->toBe(['guarded']);
});

it('tags every evaluation recorded via BoundTool with the bound tool kind', function (): void {
    $orders = [1001 => new ToolKindOrder(1001, 72)];
    $verdict = app(VerdictManager::class);
    $verdict->capability(toolKindOrderCapability('orders.tool-kind-bound', $orders));

    $bound = $verdict->bound(
        new ToolKindLookupOrderTool($orders),
        'orders.tool-kind-bound',
        new ActionContext(new ToolKindCustomer(72)),
    );
    $bound->handle(new Request(['order_id' => 1001]));

    $recorded = inMemoryEvidenceRecorder()->all();

    expect($recorded)->not->toBeEmpty()
        ->and(array_unique(array_column($recorded, 'toolKind')))->toBe(['bound']);
});

it('distinguishes a GuardedTool invocation from a BoundTool invocation against the same capability by tool_kind alone', function (): void {
    $orders = [1001 => new ToolKindOrder(1001, 72)];
    $verdict = app(VerdictManager::class);
    $verdict->capability(toolKindOrderCapability('orders.tool-kind-shared', $orders));

    $verdict->guard(
        new ToolKindLookupOrderTool($orders),
        'orders.tool-kind-shared',
        new ActionContext(new ToolKindCustomer(72)),
    )->handle(new Request(['order_id' => 1001]));

    $verdict->bound(
        new ToolKindLookupOrderTool($orders),
        'orders.tool-kind-shared',
        new ActionContext(new ToolKindCustomer(72)),
    )->handle(new Request(['order_id' => 1001]));

    $recorded = inMemoryEvidenceRecorder()->all();
    $byKind = [];

    foreach ($recorded as $evidence) {
        expect($evidence->capability)->toBe('orders.tool-kind-shared');
        $byKind[$evidence->toolKind][] = $evidence;
    }

    expect(array_keys($byKind))->toEqualCanonicalizing(['guarded', 'bound'])
        ->and($byKind['guarded'])->not->toBeEmpty()
        ->and($byKind['bound'])->not->toBeEmpty();
});
