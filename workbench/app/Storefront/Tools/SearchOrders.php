<?php

declare(strict_types=1);

namespace Workbench\App\Storefront\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;
use Workbench\App\Storefront\OrderSearchScope;

/**
 * The set-returning storefront lookup (#251): the model supplies a *filter*, never an ID — there
 * is no single record for a policy to inspect, which is exactly the shape whose safe outcome is a
 * filtered permit. Bound through Verdict, the capability's executor performs the search inside the
 * actor's {@see OrderSearchScope}; this definition supplies only what
 * the model sees.
 *
 * `handle()` throws by design: an unbound call would be an unscoped search — the very query shape
 * this capability exists to keep inside the boundary. The control arm's deliberate unscoped
 * mirror is {@see UnguardedSearchOrders}, which shares this definition's name, description, and
 * schema byte-for-byte per ADR 0023.
 */
final class SearchOrders implements Tool
{
    public function description(): string
    {
        return 'Search your orders. Optional filters: status (e.g. processing, shipped, delivered) '
            .'and item_contains (matches part of the item name).';
    }

    public function handle(Request $request): string
    {
        throw new LogicException(
            'SearchOrders must be bound through Verdict: unbound, this would run an unscoped search.',
        );
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string(),
            'item_contains' => $schema->string(),
        ];
    }
}
