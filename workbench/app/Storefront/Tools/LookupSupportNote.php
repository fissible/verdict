<?php

declare(strict_types=1);

namespace Workbench\App\Storefront\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;

/**
 * Definition-only tool: name, description, and schema for the `orders.support-notes` capability.
 * Like `CancelOrder`, its `handle()` is never called — Verdict's bound execution routes to the
 * capability's registered executor (`WorkbenchServiceProvider`), not to this definition.
 */
final class LookupSupportNote implements Tool
{
    public function description(): string
    {
        return 'Retrieve the support note text attached to an order.';
    }

    public function handle(Request $request): string
    {
        throw new LogicException('Verdict must never call the definition tool handler.');
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->integer()->required(),
        ];
    }
}
