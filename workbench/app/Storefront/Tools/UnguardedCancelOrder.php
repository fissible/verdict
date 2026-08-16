<?php

declare(strict_types=1);

namespace Workbench\App\Storefront\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Workbench\App\Storefront\ActionLog;
use Workbench\App\Storefront\Catalog;

/**
 * CONTROL ARM ONLY. `CancelOrder` is definition-only — its execution lives in the `orders.cancel`
 * capability's `executeUsing` closure, which only Verdict's bound path reaches. An unguarded arm
 * has no bound path, so this tool performs the same execution directly: resolve the order, write
 * the `ActionLog` record, return the same envelope. **Keep `handle()` in lockstep with the
 * closure in `WorkbenchServiceProvider`** — if they drift, the two arms execute different
 * mutations and the 2×2 compares nothing.
 *
 * Name, description, and schema delegate to the definition tool, because the model must see an
 * identical tool surface in both arms.
 */
final readonly class UnguardedCancelOrder implements Tool
{
    public function __construct(
        private CancelOrder $definition,
        private Catalog $catalog,
        private ActionLog $actions,
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
        $order = $this->catalog->order($request->integer('order_id'));

        $this->actions->record('orders.cancel', $order->id);

        return json_encode([
            'status' => 'cancelled',
            'order_id' => $order->id,
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->definition->schema($schema);
    }
}
