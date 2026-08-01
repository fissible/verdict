<?php

declare(strict_types=1);

namespace Workbench\App\Storefront\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;

final class RefreshShipment implements Tool
{
    public function description(): string
    {
        return 'Refresh an owned order status through the carrier integration.';
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
