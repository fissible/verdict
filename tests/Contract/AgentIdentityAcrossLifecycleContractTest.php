<?php

declare(strict_types=1);

/**
 * @contract-behaviour agent-identity-across-lifecycle
 *
 * @contract-fidelity real
 *
 * @contract-consequence PromptProvenanceRegistry keys a WeakMap by the agent Laravel AI carries from middleware to its lifecycle events.
 */
uses(TestCase::class);

use Fissible\Verdict\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;

it('preserves the same agent object from middleware to PromptingAgent', function (): void {
    $agent = new class implements Agent, HasMiddleware
    {
        use Promptable;

        public ?Agent $seenByMiddleware = null;

        public function instructions(): Stringable|string
        {
            return 'Answer plainly.';
        }

        public function middleware(): array
        {
            return [function (AgentPrompt $prompt, Closure $next): mixed {
                $this->seenByMiddleware = $prompt->agent;

                return $next($prompt);
            }];
        }
    };
    $seenByEvent = null;
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$seenByEvent): void {
        $seenByEvent = $event->prompt->agent;
    });
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway(['done']));

    $agent->prompt('go');

    expect($agent->seenByMiddleware)->toBe($agent)
        ->and($seenByEvent)->toBe($agent);
});
