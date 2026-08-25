<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
use Fissible\Verdict\Capabilities\NullCapabilityConfigurationStore;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Tests\Support\DurableCustomEvidenceRecorder;
use Fissible\Verdict\Tests\Support\VolatileCustomEvidenceRecorder;

/**
 * #310: with `verdict.capability_configurations.store` unset, the store used to be selected by
 * checking the recorder class against a literal list of the two shipped durable recorders. A
 * custom durable recorder silently fell through to the no-op store, making configuration
 * fingerprints on its retained evidence permanently unexpandable. Selection is now by declared
 * capability: the DurableEvidenceRecorder marker.
 */
function configurationStoreSelectedFor(string $recorder): CapabilityConfigurationStore
{
    config()->set('verdict.evidence.recorder', $recorder);
    config()->set('verdict.capability_configurations.store', null);
    app()->forgetInstance(CapabilityConfigurationStore::class);

    return app(CapabilityConfigurationStore::class);
}

it('selects the durable configuration store for a custom recorder that declares durability', function (): void {
    expect(configurationStoreSelectedFor(DurableCustomEvidenceRecorder::class))
        ->toBeInstanceOf(DatabaseCapabilityConfigurationStore::class);
});

it('keeps the no-op configuration store for a custom recorder that does not declare durability', function (): void {
    expect(configurationStoreSelectedFor(VolatileCustomEvidenceRecorder::class))
        ->toBeInstanceOf(NullCapabilityConfigurationStore::class);
});

it('selects the durable configuration store for the shipped database recorder', function (): void {
    expect(configurationStoreSelectedFor(DatabaseEvidenceRecorder::class))
        ->toBeInstanceOf(DatabaseCapabilityConfigurationStore::class);
});

it('selects the durable configuration store for the shipped attest recorder', function (): void {
    expect(configurationStoreSelectedFor(AttestEvidenceRecorder::class))
        ->toBeInstanceOf(DatabaseCapabilityConfigurationStore::class);
});
