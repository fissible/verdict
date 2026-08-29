<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityConfiguration;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Targets\ResourceProjection;

function fingerprintFixtureCapability(): Capability
{
    return Capability::usingPolicy(
        name: 'orders.cancel',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): mixed => null,
    );
}

it('is stable across repeated calls', function (): void {
    $capability = fingerprintFixtureCapability();

    expect($capability->configurationFingerprint())->toBe($capability->configurationFingerprint())
        ->and($capability->configurationFingerprint())->toHaveLength(64);
});

/** @verdict-claim limitation.configuration-logic */
it('produces the same fingerprint for two independently built capabilities with identical declared configuration', function (): void {
    $a = fingerprintFixtureCapability()->rateLimit(
        RateLimitPolicy::fixedWindow('per-customer', 5, 86400, fn (): array => ['customer' => 1]),
    );
    $b = fingerprintFixtureCapability()->rateLimit(
        RateLimitPolicy::fixedWindow('per-customer', 5, 86400, fn (): array => ['customer' => 1]),
    );

    expect($a->configurationFingerprint())->toBe($b->configurationFingerprint());
});

it('produces the same fingerprint regardless of the order builder methods were called in', function (): void {
    $rateLimit = RateLimitPolicy::fixedWindow('per-customer', 5, 86400, fn (): array => ['customer' => 1]);
    $claim = ExecutionClaimPolicy::named('cancel-order', fn (): array => ['order' => 1]);

    $a = fingerprintFixtureCapability()->rateLimit($rateLimit)->atMostOnce($claim);
    $b = fingerprintFixtureCapability()->atMostOnce($claim)->rateLimit($rateLimit);

    expect($a->configurationFingerprint())->toBe($b->configurationFingerprint());
});

it('produces the same fingerprint when only the confirmation reason differs', function (): void {
    $bind = fn (ActionEnvelope $envelope, mixed $target): array => ['order' => 1];

    $a = fingerprintFixtureCapability()->requiresConfirmation($bind, reason: 'Original reason.');
    $b = fingerprintFixtureCapability()->requiresConfirmation($bind, reason: 'Reworded for clarity.');

    expect($a->configurationFingerprint())->toBe($b->configurationFingerprint());
});

it('produces the same fingerprint when only the rate-limit reason differs', function (): void {
    $keyUsing = fn (): array => ['customer' => 1];

    $a = fingerprintFixtureCapability()->rateLimit(
        RateLimitPolicy::fixedWindow('per-customer', 5, 86400, $keyUsing, reason: 'Original reason.'),
    );
    $b = fingerprintFixtureCapability()->rateLimit(
        RateLimitPolicy::fixedWindow('per-customer', 5, 86400, $keyUsing, reason: 'Reworded for clarity.'),
    );

    expect($a->configurationFingerprint())->toBe($b->configurationFingerprint());
});

it('produces a different fingerprint when only the rate-limit is raised', function (): void {
    $keyUsing = fn (): array => ['customer' => 1];

    $a = fingerprintFixtureCapability()->rateLimit(
        RateLimitPolicy::fixedWindow('per-customer', 5, 86400, $keyUsing),
    );
    $b = fingerprintFixtureCapability()->rateLimit(
        RateLimitPolicy::fixedWindow('per-customer', 5000, 86400, $keyUsing),
    );

    expect($a->configurationFingerprint())->not->toBe($b->configurationFingerprint());
});

it('produces a different fingerprint when the execution-target strategy differs', function (): void {
    $identityUsing = fn (): array => ['order' => 1];

    $a = fingerprintFixtureCapability()->executionTarget(
        ExecutionTargetPolicy::acceptStaleSnapshot('order-primary-key', $identityUsing),
    );
    $b = fingerprintFixtureCapability()->executionTarget(
        ExecutionTargetPolicy::refresh('order-primary-key', $identityUsing, fn (): mixed => null),
    );

    expect($a->configurationFingerprint())->not->toBe($b->configurationFingerprint());
});

it('produces a different fingerprint when only the confirmation TTL differs', function (): void {
    $bind = fn (ActionEnvelope $envelope, mixed $target): array => ['order' => 1];

    $a = fingerprintFixtureCapability()->requiresConfirmation($bind, ttlSeconds: 60);
    $b = fingerprintFixtureCapability()->requiresConfirmation($bind, ttlSeconds: 3600);

    expect($a->configurationFingerprint())->not->toBe($b->configurationFingerprint());
});

it('produces a different fingerprint when only the application-supplied configuration version differs', function (): void {
    $a = fingerprintFixtureCapability()->configurationVersion('v1');
    $b = fingerprintFixtureCapability()->configurationVersion('v2');

    expect($a->configurationFingerprint())->not->toBe($b->configurationFingerprint())
        ->and(fingerprintFixtureCapability()->configurationFingerprint())
        ->not->toBe($a->configurationFingerprint());
});

it('is not affected by the executor, target resolver, or approval binding closures', function (): void {
    $a = fingerprintFixtureCapability()->executeUsing(fn (AuthorizedAction $action): string => 'a');
    $b = fingerprintFixtureCapability()->executeUsing(fn (AuthorizedAction $action): string => 'completely different behavior');

    expect($a->configurationFingerprint())->toBe($b->configurationFingerprint());
});

it('passes custom configuration stores only the materialized closure-free value object', function (): void {
    $store = new class implements CapabilityConfigurationStore
    {
        public ?CapabilityConfiguration $recorded = null;

        public function record(CapabilityConfiguration $configuration): bool
        {
            $this->recorded = $configuration;

            return true;
        }
    };
    $capability = Capability::usingPolicy(
        name: 'orders.cancel',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): mixed => new stdClass,
    )->executeUsing(fn (AuthorizedAction $action): string => 'application behavior')
        ->requiresConfirmation(fn (ActionEnvelope $envelope, mixed $target): array => ['order' => 1]);

    (new CapabilityRegistry($store))->register($capability);

    expect($store->recorded)->not->toBeNull()
        ->and(array_keys(get_object_vars($store->recorded)))->toBe(['fingerprint', 'capability', 'declared'])
        ->and($store->recorded?->fingerprint)->toBe($capability->configurationFingerprint())
        ->and($store->recorded?->declared)->toBe($capability->declaredConfiguration())
        ->and(ArgumentFingerprint::make($store->recorded?->declared))->toBe($store->recorded?->fingerprint)
        ->and(json_encode($store->recorded, JSON_THROW_ON_ERROR))->not->toContain('application behavior');
});

it('does not let a declared resource projection move the content-addressed identity', function (): void {
    // A projection declares which bytes an evaluation instrument measures (#366). It changes no
    // authorization behaviour, so it is not the "security-material configuration" this map is
    // documented to hold, and ADR 0017 keeps that map sparse on purpose. Letting it in would move
    // the fingerprint of any capability that adopts one — a content-addressed identity changing for
    // a declaration that alters nothing a decision depends on.
    $plain = fingerprintFixtureCapability();
    $declaring = $plain->resourceProjection(ResourceProjection::declared('order-cancel/v1', fn (): array => ['id' => 1]));

    expect($declaring->declaredConfiguration())->toBe($plain->declaredConfiguration())
        ->and($declaring->configurationFingerprint())->toBe($plain->configurationFingerprint());

    // And two capabilities differing ONLY in their declared contract remain the same configuration,
    // which is the same claim stated where it would actually bite.
    $other = $plain->resourceProjection(ResourceProjection::declared('order-cancel/v2', fn (): array => ['id' => 1]));

    expect($other->configurationFingerprint())->toBe($declaring->configurationFingerprint());

    // The declaration is still reachable — it is kept off the fingerprint, not dropped.
    expect($declaring->declaredResourceProjection()?->contract)->toBe('order-cancel/v1');
});
