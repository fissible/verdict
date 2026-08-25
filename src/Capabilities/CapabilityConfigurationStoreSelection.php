<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Fissible\Verdict\Contracts\DurableEvidenceRecorder;

/**
 * The one place that decides which capability-configuration store an unset
 * `verdict.capability_configurations.store` falls through to. Selection is by the recorder's
 * declared capability — the DurableEvidenceRecorder marker — not by class name (#310): a literal
 * class list silently sent custom durable recorders to the no-op store, leaving configuration
 * fingerprints on their retained evidence permanently unexpandable. Shared by the service
 * provider (which acts on it) and verdict:validate (which warns on it), so the audit can never
 * drift from the wiring it audits.
 */
final class CapabilityConfigurationStoreSelection
{
    /** @return class-string */
    public static function forRecorder(mixed $recorder): string
    {
        return is_string($recorder) && is_a($recorder, DurableEvidenceRecorder::class, true)
            ? DatabaseCapabilityConfigurationStore::class
            : NullCapabilityConfigurationStore::class;
    }
}
