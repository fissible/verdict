<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\InMemoryCapabilityConfigurationStore;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;

/**
 * #358, Site 1 — the "kill shot". `CapabilityConfigurationStore` is bound `scoped`, but
 * `CapabilityRegistry` is a `singleton` that captured the store in its constructor. Under Octane,
 * after `Container::forgetScopedInstances()` the singleton keeps writing to the discarded boot-scope
 * store while every request resolves a fresh one — the exact stale-instance shape the provider's own
 * #183/ADR-0020 comments forbid for the evidence recorder. Registration's write is a per-decision
 * audit trail, so the blast radius is a misrouted audit write (no authorization outcome changes),
 * but it violates the codebase's own stated lifetime invariant.
 *
 * A dynamic per-request `Verdict::capability()` registration is the path that exercises it.
 */
function scopedStoreCapability(string $name): Capability
{
    return Capability::usingPolicy(
        name: $name,
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): string => (string) $envelope->proposal->arguments['id'],
    );
}

/** @return list<string> the capability names a store recorded */
function recordedCapabilities(CapabilityConfigurationStore $store): array
{
    return collect($store->all())->pluck('capability')->values()->all();
}

it('records a dynamically-registered capability to the current scoped store, not the discarded boot one (#358)', function (): void {
    $created = [];
    app()->scoped(CapabilityConfigurationStore::class, function () use (&$created): CapabilityConfigurationStore {
        return $created[] = new InMemoryCapabilityConfigurationStore;
    });

    // The registry singleton may have resolved at boot against the original binding; drop it so it
    // re-resolves and its store comes from the spy factory above.
    app()->forgetInstance(CapabilityRegistry::class);

    $registry = app(CapabilityRegistry::class);
    $registry->register(scopedStoreCapability('orders.a'));

    $bootStore = app(CapabilityConfigurationStore::class);
    expect($bootStore)->toBe($created[0])
        ->and(recordedCapabilities($bootStore))->toContain('orders.a');

    // Octane discards scoped instances between requests.
    app()->forgetScopedInstances();

    $requestStore = app(CapabilityConfigurationStore::class);
    expect($requestStore)->not->toBe($bootStore);

    // A per-request dynamic registration.
    $registry->register(scopedStoreCapability('orders.b'));

    // orders.b must be recorded to the CURRENT scoped store, and must NOT land on the discarded one.
    expect(recordedCapabilities($requestStore))->toContain('orders.b')
        ->and(recordedCapabilities($bootStore))->not->toContain('orders.b');

    // A second request boundary — the resolution must follow the scope on EVERY reset, not just the
    // first, so a "refresh once" implementation cannot pass.
    app()->forgetScopedInstances();

    $secondStore = app(CapabilityConfigurationStore::class);
    expect($secondStore)->not->toBe($requestStore);

    $registry->register(scopedStoreCapability('orders.c'));

    expect(recordedCapabilities($secondStore))->toContain('orders.c')
        ->and(recordedCapabilities($requestStore))->not->toContain('orders.c');
});
