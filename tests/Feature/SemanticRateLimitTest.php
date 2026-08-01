<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\RateLimits\RateLimitConsumption;
use Fissible\Verdict\RateLimits\RateLimitManager;
use Fissible\Verdict\RateLimits\RateLimitOutcome;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\VerdictManager;

final class SemanticRateLimitClock implements Clock
{
    public function __construct(public DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

final readonly class SemanticRateLimitTarget
{
    public function __construct(public int $id) {}
}

function semanticRateLimitEnvelope(int $actor, int $target = 1001): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal('orders.lookup', ['order_id' => $target]),
        context: new ActionContext($actor, ['tenant_id' => 'store-1']),
    );
}

function semanticRateLimitCapability(int &$executions, int $limit = 2): Capability
{
    return Capability::usingPolicy(
        name: 'orders.lookup',
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): SemanticRateLimitTarget => new SemanticRateLimitTarget(
            (int) $envelope->proposal->arguments['order_id'],
        ),
    )->rateLimit(RateLimitPolicy::fixedWindow(
        name: 'per-principal',
        limit: $limit,
        windowSeconds: 60,
        keyUsing: fn (ActionEnvelope $envelope, SemanticRateLimitTarget $target): array => [
            'tenant_id' => $envelope->context->metadata['tenant_id'],
            'actor_id' => $envelope->context->actor,
        ],
        reason: 'Order lookup limit exceeded.',
    ))->executeUsing(function (AuthorizedAction $action) use (&$executions): string {
        $executions++;

        return 'executed';
    });
}

beforeEach(function (): void {
    $this->app->instance(Clock::class, new SemanticRateLimitClock(
        new DateTimeImmutable('2026-08-01 12:00:15', new DateTimeZone('UTC')),
    ));
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
});

it('throttles an authorized execution attempt after the configured limit', function (): void {
    $executions = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(semanticRateLimitCapability($executions));

    $first = $verdict->runBound(semanticRateLimitEnvelope(72));
    $second = $verdict->runBound(semanticRateLimitEnvelope(72));
    $third = $verdict->runBound(semanticRateLimitEnvelope(72));

    expect($first->executed)->toBeTrue()
        ->and($second->executed)->toBeTrue()
        ->and($third->executed)->toBeFalse()
        ->and($third->evaluation->stage->value)->toBe('rate_limit')
        ->and($third->evaluation->decision->disposition->value)->toBe('throttle')
        ->and($executions)->toBe(2);

    $evidence = app(EvidenceRecorder::class);
    expect($evidence)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $evidence instanceof InMemoryEvidenceRecorder) {
        return;
    }

    $latest = $evidence->latest();

    expect($latest?->rateLimitPolicy)->toBe('per-principal')
        ->and($latest?->rateLimitLimit)->toBe(2)
        ->and($latest?->rateLimitRemaining)->toBe(0)
        ->and($latest?->rateLimitResetAt?->getTimestamp())->toBe(
            (new DateTimeImmutable('2026-08-01 12:01:00', new DateTimeZone('UTC')))->getTimestamp(),
        )
        ->and($latest?->rateLimitKeyFingerprint)->toHaveLength(64)
        ->and($latest?->rateLimitKeyFingerprint)->not->toContain('72');
});

it('isolates buckets using trusted application-defined bindings', function (): void {
    $executions = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(semanticRateLimitCapability($executions, limit: 1));

    expect($verdict->runBound(semanticRateLimitEnvelope(72))->executed)->toBeTrue()
        ->and($verdict->runBound(semanticRateLimitEnvelope(72))->executed)->toBeFalse()
        ->and($verdict->runBound(semanticRateLimitEnvelope(73))->executed)->toBeTrue()
        ->and($executions)->toBe(2);
});

it('namespaces identical bindings by capability and policy configuration', function (): void {
    $policy = RateLimitPolicy::fixedWindow(
        name: 'per-principal',
        limit: 1,
        windowSeconds: 60,
        keyUsing: fn (ActionEnvelope $envelope): array => ['actor_id' => $envelope->context->actor],
    );
    $first = Capability::usingPolicy('orders.lookup', 'view', fn (): null => null)->rateLimit($policy);
    $second = Capability::usingPolicy('returns.lookup', 'view', fn (): null => null)->rateLimit($policy);
    $manager = app(RateLimitManager::class);

    expect($manager->consume($first, semanticRateLimitEnvelope(72), null)->permitsExecution())->toBeTrue()
        ->and($manager->consume($second, semanticRateLimitEnvelope(72), null)->permitsExecution())->toBeTrue();
});

it('also enforces configured limits on migration-guard executions', function (): void {
    $executions = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(semanticRateLimitCapability($executions, limit: 1));
    $envelope = semanticRateLimitEnvelope(72);
    $guardExecutions = 0;

    $first = $verdict->run($envelope, function () use (&$guardExecutions): string {
        $guardExecutions++;

        return 'executed';
    });
    $second = $verdict->run($envelope, function () use (&$guardExecutions): string {
        $guardExecutions++;

        return 'executed';
    });

    expect($first->executed)->toBeTrue()
        ->and($second->executed)->toBeFalse()
        ->and($second->evaluation->stage->value)->toBe('rate_limit')
        ->and($guardExecutions)->toBe(1);
});

it('does not consume a unit for an execution-stage policy denial', function (): void {
    $executions = 0;
    $calls = 0;
    $this->app->instance(CapabilityAuthorizer::class, new class($calls) implements CapabilityAuthorizer
    {
        public function __construct(private int &$calls) {}

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            $this->calls++;

            return $this->calls === 2 ? Decision::deny('Authority changed.') : Decision::permit();
        }
    });
    $verdict = app(VerdictManager::class);
    $verdict->capability(semanticRateLimitCapability($executions, limit: 1));

    $denied = $verdict->runBound(semanticRateLimitEnvelope(72));
    $permitted = $verdict->runBound(semanticRateLimitEnvelope(72));

    expect($denied->executed)->toBeFalse()
        ->and($denied->evaluation->stage->value)->toBe('execution')
        ->and($permitted->executed)->toBeTrue()
        ->and($executions)->toBe(1);
});

it('propagates store failures without executing the capability', function (): void {
    $executions = 0;
    $this->app->instance(RateLimitStore::class, new class implements RateLimitStore
    {
        public function consume(RateLimitConsumption $consumption): RateLimitOutcome
        {
            throw new RuntimeException('Rate-limit database unavailable.');
        }
    });
    $verdict = app(VerdictManager::class);
    $verdict->capability(semanticRateLimitCapability($executions));

    expect(fn () => $verdict->runBound(semanticRateLimitEnvelope(72)))
        ->toThrow(RuntimeException::class, 'Rate-limit database unavailable.');
    expect($executions)->toBe(0);
});
