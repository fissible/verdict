<?php

declare(strict_types=1);

/**
 * @contract-behaviour tool-name-resolution
 *
 * @contract-fidelity constructed
 *
 * @contract-consequence Wrapped Verdict tools must advertise their inner tool's name so the model can request the intended capability.
 */
uses('Fissible\\Verdict\\Tests\\TestCase');

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

it('resolves a wrapped tool through Laravel AI name resolution', function (): void {
    $inner = new class implements Tool
    {
        public function name(): string
        {
            return 'lookup_order';
        }

        public function description(): Stringable|string
        {
            return 'Look up an order.';
        }

        /**
         * @return array<string, Type>
         */
        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function handle(Request $request): Stringable|string
        {
            return 'unused';
        }
    };

    $guarded = app(VerdictManager::class)->guard($inner, 'orders.view', new ActionContext([]));

    expect(ToolNameResolver::resolve($guarded))->toBe('lookup_order');
});
