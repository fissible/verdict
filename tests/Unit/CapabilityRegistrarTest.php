<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\CapabilityDiscovery;
use Fissible\Verdict\Capabilities\CapabilityRegistrar;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Exceptions\CapabilityDefinitionFailed;
use Fissible\Verdict\Tests\Fixtures\Capabilities\AffirmedCapability;
use Fissible\Verdict\Tests\Fixtures\DuplicateCapabilities\DuplicateOneCapability;
use Fissible\Verdict\Tests\Fixtures\ManyBrokenCapabilities\BrokenClaimCapability;
use Fissible\Verdict\Tests\Fixtures\ManyBrokenCapabilities\BrokenLimitCapability;
use Fissible\Verdict\Tests\Fixtures\ThrowingCapabilities\ThrowingRateLimitCapability;

function registrarFor(CapabilityRegistry $registry, string ...$directories): CapabilityRegistrar
{
    return new CapabilityRegistrar(
        discovery: new CapabilityDiscovery(
            rootPath: dirname(__DIR__).'/Fixtures',
            rootNamespace: 'Fissible\\Verdict\\Tests\\Fixtures\\',
            paths: array_map(static fn (string $directory): string => dirname(__DIR__).'/Fixtures/'.$directory, $directories),
        ),
        capabilities: $registry,
    );
}

it('registers an affirmed definition through the ordinary registry', function (): void {
    $registry = new CapabilityRegistry;

    registrarFor($registry, 'Capabilities')->registerDiscovered();

    // Indistinguishable downstream: the registry holds the same kind of object a provider registers.
    expect($registry->has('fixtures.affirmed'))->toBeTrue()
        ->and($registry->has('fixtures.nested'))->toBeTrue()
        ->and($registry->get('fixtures.affirmed')->name)->toBe('fixtures.affirmed');
});

it('never registers a definition that did not affirm itself', function (): void {
    $registry = new CapabilityRegistry;

    registrarFor($registry, 'Capabilities')->registerDiscovered();

    expect($registry->has('fixtures.unaffirmed'))->toBeFalse();
});

/**
 * The boot must die. Skipping would make an affirmed capability silently absent, which surfaces
 * later as a confusing unregistered-capability error — and a capability that denies everything reads
 * like a policy bug whose "fix" under pressure is a permissive path. See ADR 0027 §4.
 */
it('lets a throwing definition fail the boot rather than swallowing it', function (): void {
    expect(fn (): mixed => registrarFor(new CapabilityRegistry, 'ThrowingCapabilities')->registerDiscovered())
        ->toThrow(CapabilityDefinitionFailed::class);
});

/**
 * The message shape is load-bearing design, not cosmetics: without the second exit named, the
 * alternatives a developer discovers are deleting the file or hacking out the TODO, both worse.
 */
it('names both legitimate exits when a definition fails to build', function (): void {
    $failure = null;

    try {
        registrarFor(new CapabilityRegistry, 'ThrowingCapabilities')->registerDiscovered();
    } catch (CapabilityDefinitionFailed $exception) {
        $failure = $exception;
    }

    expect($failure)->not->toBeNull()
        ->and($failure?->getMessage())->toContain(ThrowingRateLimitCapability::class)
        ->and($failure?->getMessage())->toContain('Finish the TODOs')
        ->and($failure?->getMessage())->toContain('remove `implements DefinesCapability`')
        // The developer's own words become the diagnosis.
        ->and($failure?->getPrevious()?->getMessage())
        ->toContain('TODO: choose application-owned rate-limit scope, limit, window, and binding.');
});

it('fails when a discovered name is already registered manually, naming the manual registration', function (): void {
    $registry = new CapabilityRegistry;
    $registry->register(AffirmedCapability::make());

    expect(fn (): mixed => registrarFor($registry, 'Capabilities')->registerDiscovered())
        ->toThrow(CapabilityDefinitionFailed::class, 'already registered');
});

it('fails when two discovered definitions produce the same capability name, naming both', function (): void {
    $failure = null;

    try {
        registrarFor(new CapabilityRegistry, 'DuplicateCapabilities')->registerDiscovered();
    } catch (CapabilityDefinitionFailed $exception) {
        $failure = $exception;
    }

    expect($failure?->getMessage())->toContain(DuplicateOneCapability::class)
        ->and($failure?->getMessage())->toContain('DuplicateTwoCapability')
        ->and($failure?->getMessage())->toContain('fixtures.duplicate');
});

/**
 * Boot is the only context that can report these: `php artisan verdict:validate` bootstraps the
 * application before dispatching, so a throwing definition kills the command before it can report.
 * Aggregating here is what preserves fix-all-at-once — see ADR 0027 §4.
 */
it('reports every broken definition in one pass rather than only the first', function (): void {
    $failure = null;

    try {
        registrarFor(new CapabilityRegistry, 'ManyBrokenCapabilities')->registerDiscovered();
    } catch (CapabilityDefinitionFailed $exception) {
        $failure = $exception;
    }

    $message = (string) $failure?->getMessage();

    expect($message)->toContain('2 capability definitions')
        ->and($message)->toContain(BrokenClaimCapability::class)
        ->and($message)->toContain(BrokenLimitCapability::class)
        // Each entry keeps the per-entry contract: cause, and both legitimate exits.
        ->and($message)->toContain('TODO: bind duplicate admission to canonical application-owned identity.')
        ->and($message)->toContain('TODO: choose application-owned rate-limit scope, limit, window, and binding.')
        ->and(substr_count($message, 'Finish the TODOs'))->toBe(2)
        ->and(substr_count($message, 'remove `implements DefinesCapability`'))->toBe(2);
});

/** Aggregation must cost the common case nothing: one failure still reads exactly as it did. */
it('leaves a single failure message unchanged by aggregation', function (): void {
    $failure = null;

    try {
        registrarFor(new CapabilityRegistry, 'ThrowingCapabilities')->registerDiscovered();
    } catch (CapabilityDefinitionFailed $exception) {
        $failure = $exception;
    }

    expect($failure?->getMessage())
        ->toBe(CapabilityDefinitionFailed::forClass(
            ThrowingRateLimitCapability::class,
            new LogicException('TODO: choose application-owned rate-limit scope, limit, window, and binding.'),
        )->getMessage())
        ->and($failure?->getMessage())->not->toContain('capability definitions could not be registered');
});

it('registers nothing when any discovered definition fails', function (): void {
    $registry = new CapabilityRegistry;

    expect(fn (): mixed => registrarFor($registry, 'MixedCapabilities')->registerDiscovered())
        ->toThrow(CapabilityDefinitionFailed::class);

    // All-or-nothing: a boot that is going to die must not leave half a security surface behind.
    expect($registry->all())->toBe([]);
});
