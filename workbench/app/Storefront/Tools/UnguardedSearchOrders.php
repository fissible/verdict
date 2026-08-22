<?php

declare(strict_types=1);

namespace Workbench\App\Storefront\Tools;

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Evaluation\ConnectionPredicateCapture;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;
use Workbench\App\Storefront\StorefrontOrders;

/**
 * CONTROL ARM ONLY. The unscoped mirror of the `orders.search` capability: the same filter
 * surface the model sees, with the actor scope absent — which is the breach observable ("foreign
 * record present in results") made reachable, per ADR 0023's byte-identical control-arm rule.
 * **Keep the filter application in lockstep with the `orders.search` closure in
 * `WorkbenchServiceProvider`** — the arms must differ in the scope and nothing else.
 *
 * Constructed with a {@see ConnectionPredicateCapture}, the mirror opens the capture window
 * around its own query — the control arm has no `VerdictManager` to open one (#251 round 5), and
 * without a window the control arm captures no predicates, `executedPredicateNotScopedAs()` lands
 * unmeasured, and every filtered-permit trial is structurally unmeasurable in a live run. The
 * attribution envelope is built from the tool call itself; its context actor is a label, because
 * no trusted actor exists on an unguarded path — which is the point.
 */
final readonly class UnguardedSearchOrders implements Tool
{
    public function __construct(
        private SearchOrders $definition,
        private ?ConnectionPredicateCapture $predicates = null,
    ) {}

    public function name(): string
    {
        return ToolNameResolver::resolve($this->definition);
    }

    public function description(): string
    {
        return $this->definition->description();
    }

    public function handle(Request $request): string
    {
        if ($this->predicates === null) {
            return $this->search($request);
        }

        $result = $this->predicates->around(
            ActionEnvelope::wrap(
                new ActionProposal('orders.search', $request->all()),
                new ActionContext('control-arm'),
            ),
            fn (): string => $this->search($request),
        );

        if (! is_string($result)) {
            throw new LogicException('The capture window must return the search result unchanged.');
        }

        return $result;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->definition->schema($schema);
    }

    private function search(Request $request): string
    {
        $query = app(DatabaseManager::class)->connection()->table(StorefrontOrders::TABLE);

        // KEEP IN LOCKSTEP with the orders.search executor, minus the scope.
        $arguments = $request->all();
        $status = $arguments['status'] ?? null;
        $itemContains = $arguments['item_contains'] ?? null;

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if (is_string($itemContains) && $itemContains !== '') {
            $query->where('item', 'like', '%'.$itemContains.'%');
        }

        $orders = array_map(
            static fn (object $row): array => [
                'id' => (int) $row->id,
                'customer_id' => (int) $row->customer_id,
                'item' => (string) $row->item,
                'status' => (string) $row->status,
            ],
            $query->orderBy('id')->get(['id', 'customer_id', 'item', 'status'])->all(),
        );

        return json_encode(['orders' => $orders], JSON_THROW_ON_ERROR);
    }
}
