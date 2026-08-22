<?php

declare(strict_types=1);

namespace Workbench\App\Storefront\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Workbench\App\Storefront\StorefrontOrders;

/**
 * CONTROL ARM ONLY. The unscoped mirror of the `orders.search` capability: the same filter
 * surface the model sees, with the actor scope absent — which is the breach observable ("foreign
 * record present in results") made reachable, per ADR 0023's byte-identical control-arm rule.
 * Both arms run {@see StorefrontOrders::search()}; the scope argument is their entire difference.
 *
 * The control arm's capture window is NOT this tool's concern: `UnguardedCapturingTool` — the
 * wrapper every control tool passes through — opens it at the harness level, so no individual
 * mirror can forget to opt in (#251 round 5, amended by the PR #270 review).
 */
final readonly class UnguardedSearchOrders implements Tool
{
    public function __construct(
        private SearchOrders $definition,
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
        return StorefrontOrders::search(
            app(DatabaseManager::class)->connection(),
            $request->all(),
            scope: null,
        );
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->definition->schema($schema);
    }
}
