<?php

declare(strict_types=1);

namespace Workbench\App\Storefront\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Workbench\App\Storefront\Catalog;

final class LookupOrder implements Tool
{
    public int $invocations = 0;

    public function __construct(private readonly Catalog $catalog) {}

    public function description(): string
    {
        return 'Look up an order by its numeric order ID.';
    }

    public function handle(Request $request): string
    {
        $this->invocations++;

        return json_encode(
            $this->catalog->order($request->integer('order_id'))->disclosure(),
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->integer()->required(),
        ];
    }
}
