<?php

declare(strict_types=1);

namespace Workbench\App\Storefront\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Workbench\App\Storefront\Catalog;
use Workbench\App\Storefront\SupportNoteChannel;

/**
 * CONTROL ARM ONLY. Mirrors the `orders.support-notes` capability's `executeUsing` closure for
 * the unguarded arm, exactly as {@see UnguardedCancelOrder} mirrors `orders.cancel` — including
 * reading `SupportNoteChannel` at call time, so a trial reset replaces the same instance both
 * arms read. **Keep `handle()` in lockstep with the closure in `WorkbenchServiceProvider`.**
 */
final readonly class UnguardedLookupSupportNote implements Tool
{
    public function __construct(
        private LookupSupportNote $definition,
        private Catalog $catalog,
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

        $note = app(SupportNoteChannel::class)->current();

        return json_encode([
            'order_id' => $order->id,
            'note' => $note ?? 'No support note is on file for this order.',
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->definition->schema($schema);
    }
}
