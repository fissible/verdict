<?php

declare(strict_types=1);

/**
 * @contract-behaviour middleware-pipeline-invocation
 *
 * @contract-fidelity real
 *
 * @contract-consequence Verdict records prompt provenance only when Laravel AI's real prompt pipeline invokes its registered middleware.
 */
uses(TestCase::class);

use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\LaravelAi\VerdictProvenanceMiddleware;
use Fissible\Verdict\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Promptable;

it('invokes Verdict provenance middleware through Laravel AI real prompt pipeline', function (): void {
    $middleware = new VerdictProvenanceMiddleware(app(ProvenanceLedger::class), Trust::Untrusted, DataClass::Internal);
    $agent = new class($middleware) implements Agent, HasMiddleware
    {
        use Promptable;

        public function __construct(private VerdictProvenanceMiddleware $middleware) {}

        public function instructions(): Stringable|string
        {
            return 'Answer plainly.';
        }

        public function middleware(): array
        {
            return [$this->middleware];
        }
    };
    $invocationId = null;
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$invocationId): void {
        $invocationId = $event->invocationId;
    });
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway(['done']));

    $agent->prompt('go');

    expect(is_string($invocationId))->toBeTrue()
        ->and(count(app(ProvenanceLedger::class)->forCorrelation($invocationId)))->toBe(1)
        ->and(class_exists(FakeTextGateway::class))->toBeTrue();
});
