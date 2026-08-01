<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Exceptions\CapabilityAlreadyRegistered;
use Fissible\Verdict\Exceptions\UnknownCapability;

function testCapability(string $name = 'orders.view'): Capability
{
    return Capability::usingPolicy(
        name: $name,
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): string => (string) $envelope->proposal->arguments['order_id'],
    );
}

it('registers and retrieves a capability', function (): void {
    $registry = new CapabilityRegistry;
    $capability = testCapability();

    $registry->register($capability);

    expect($registry->has('orders.view'))->toBeTrue()
        ->and($registry->get('orders.view'))->toBe($capability)
        ->and($registry->all())->toBe(['orders.view' => $capability]);
});

it('rejects duplicate capability names', function (): void {
    $registry = new CapabilityRegistry;
    $registry->register(testCapability());

    $registry->register(testCapability());
})->throws(CapabilityAlreadyRegistered::class);

it('rejects unknown capability names', function (): void {
    (new CapabilityRegistry)->get('orders.cancel');
})->throws(UnknownCapability::class);
