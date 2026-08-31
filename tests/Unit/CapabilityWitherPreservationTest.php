<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\TargetSource;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Targets\ResourceProjection;

/**
 * Every with-style method returns a new instance carrying the complete prior state — the property
 * `configurationFingerprint`'s docblock relies on it, because the fingerprint is computed once on
 * the fully composed capability. Nothing enforced it: each wither restated the whole constructor
 * call by hand, and one that forgot a field would silently reset it to null. A capability that
 * quietly loses its execution-claim policy between two fluent calls is an at-most-once guarantee
 * that stops existing without a single test going red.
 *
 * This locks the property for every wither at once, so it holds for fields added later too (#331).
 */

/** A capability with every field set to a distinctive, non-default value. */
function fullyComposedCapability(): Capability
{
    return Capability::usingPolicyForContextTarget('orders.refund', 'refund', fn (): int => 1)
        ->describeForApprover(fn (): string => 'Refund order #1')
        ->executeUsing(fn (): string => 'executed')
        ->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $envelope, mixed $target): array => ['id' => 1],
            reason: 'A human must approve a refund.',
            ttlSeconds: 300,
        )
        ->rateLimit(RateLimitPolicy::fixedWindow('refunds-per-hour', 3, 3600, fn (): array => ['k' => 'v']))
        ->atMostOnce(ExecutionClaimPolicy::named('refund-order', fn (): array => ['k' => 'v']))
        ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot('refund-snapshot', fn (): array => ['k' => 'v']))
        ->resourceProjection(ResourceProjection::declared('refund-order/v1', fn (): array => ['k' => 'v']))
        ->configurationVersion('2026-08-26.1')
        ->requiresIntentRecord();
}

/** @return array<string, mixed> */
function capabilityState(Capability $capability): array
{
    $state = [];

    foreach ((new ReflectionClass($capability))->getProperties() as $property) {
        // Derived, not carried: the fingerprint is recomputed from the fields below on every
        // construction, and ADR 0017's own tests own what it covers. Guarding the inputs is what
        // makes the derivation trustworthy.
        if ($property->getName() === 'configurationFingerprint') {
            continue;
        }

        $state[$property->getName()] = $property->getValue($capability);
    }

    return $state;
}

/**
 * @return array<string, array{0: Closure(Capability): Capability, 1: list<string>}>
 */
function capabilityWithers(): array
{
    return [
        // wither => [invocation, the properties it is allowed to change]
        'describeForApprover' => [
            fn (Capability $c): Capability => $c->describeForApprover(fn (): string => 'Replaced.'),
            ['approverSummaryDescriber'],
        ],
        'executeUsing' => [fn (Capability $c): Capability => $c->executeUsing(fn (): string => 'replaced'), ['executor']],
        'requiresConfirmation' => [
            fn (Capability $c): Capability => $c->requiresConfirmation(fn (): array => ['replaced' => true], 'Replaced.', 60),
            ['approvalBindingResolver', 'confirmationReason', 'confirmationTtlSeconds'],
        ],
        'rateLimit' => [
            fn (Capability $c): Capability => $c->rateLimit(RateLimitPolicy::fixedWindow('replaced', 9, 9, fn (): array => ['k' => 'v'])),
            ['rateLimitPolicy'],
        ],
        'atMostOnce' => [
            fn (Capability $c): Capability => $c->atMostOnce(ExecutionClaimPolicy::named('replaced', fn (): array => ['k' => 'v'])),
            ['executionClaimPolicy'],
        ],
        'executionTarget' => [
            fn (Capability $c): Capability => $c->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot('replaced', fn (): array => ['k' => 'v'])),
            ['executionTargetPolicy'],
        ],
        'resourceProjection' => [
            fn (Capability $c): Capability => $c->resourceProjection(ResourceProjection::declared('replaced/v1', fn (): array => ['k' => 'v'])),
            ['resourceProjection'],
        ],
        'configurationVersion' => [
            fn (Capability $c): Capability => $c->configurationVersion('replaced'),
            ['configurationVersion'],
        ],
        'requiresIntentRecord' => [
            fn (Capability $c): Capability => $c->requiresIntentRecord(false),
            ['requiresIntentRecord'],
        ],
    ];
}

it('builds a fixture in which every field is actually populated', function (): void {
    // Without this the preservation guard below can pass for the wrong reason: the fixture is
    // composed BY the withers under test, so a wither that drops a field corrupts the baseline
    // too, and the before/after comparison finds null preserved as null. Verified by mutation —
    // dropping executionClaimPolicy from executionTarget() is invisible to the comparison and
    // caught here.
    $state = capabilityState(fullyComposedCapability());
    $unset = array_keys(array_filter($state, static fn (mixed $value): bool => $value === null));

    expect($unset)->toBe([], 'The fixture must set every field: '.implode(', ', $unset).' came back null.')
        ->and($state)->toHaveCount(15)
        ->and($state['targetSource'])->toBe(TargetSource::Context);
});

it('covers every with-style method on the capability', function (): void {
    // The guard is only as good as its coverage, and a wither added later would otherwise be
    // silently unguarded — exactly the failure mode this file exists to prevent.
    $withers = [];

    foreach ((new ReflectionClass(Capability::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $returns = $method->getReturnType();

        // A `self` return type reflects as the literal string 'self', never the resolved class.
        if ($method->isStatic() || ! $returns instanceof ReflectionNamedType) {
            continue;
        }

        if (! in_array($returns->getName(), ['self', 'static', Capability::class], true)) {
            continue;
        }

        $withers[] = $method->getName();
    }

    sort($withers);
    $covered = array_keys(capabilityWithers());
    sort($covered);

    expect($withers)->toBe($covered);
});

it('preserves every field it does not set', function (string $wither): void {
    [$invoke, $changes] = capabilityWithers()[$wither];

    $before = fullyComposedCapability();
    $after = $invoke($before);

    $beforeState = capabilityState($before);
    $afterState = capabilityState($after);

    foreach ($beforeState as $property => $value) {
        if (in_array($property, $changes, true)) {
            continue;
        }

        expect($afterState[$property])->toBe(
            $value,
            "[{$wither}] dropped [{$property}]: a wither must carry every field it does not set.",
        );
    }
})->with(array_keys(capabilityWithers()));

it('actually changes what it sets', function (string $wither): void {
    // The other direction, so a wither cannot pass the preservation guard by ignoring its argument.
    [$invoke, $changes] = capabilityWithers()[$wither];

    $before = fullyComposedCapability();
    $after = $invoke($before);

    $beforeState = capabilityState($before);
    $afterState = capabilityState($after);
    $changed = array_values(array_filter(
        $changes,
        fn (string $property): bool => $afterState[$property] !== $beforeState[$property],
    ));

    expect($changed)->not->toBe([], "[{$wither}] changed none of its declared fields.");
})->with(array_keys(capabilityWithers()));

it('keeps a non-default target source through the whole fluent chain', function (): void {
    // The field most likely to be dropped silently: it is constructor-defaulted, last in the
    // parameter list, and only a context-resolved capability makes a loss observable — the exact
    // shape ADR 0025's proposal-is-not-in-scope guarantee depends on.
    expect(fullyComposedCapability()->targetSource)->toBe(TargetSource::Context);
});
