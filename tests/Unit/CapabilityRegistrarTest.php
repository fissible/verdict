<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\CapabilityDiscovery;
use Fissible\Verdict\Capabilities\CapabilityRegistrar;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Exceptions\CapabilityDefinitionFailed;
use Fissible\Verdict\Tests\Fixtures\Capabilities\AffirmedCapability;
use Fissible\Verdict\Tests\Fixtures\DuplicateCapabilities\DuplicateOneCapability;
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
