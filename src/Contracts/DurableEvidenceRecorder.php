<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

/**
 * Marker: this evidence recorder retains what it records, so the configuration fingerprints on its
 * evidence must stay expandable. When `verdict.capability_configurations.store` is null, Verdict
 * selects the durable capability-configuration store for recorders that implement this interface
 * and the no-op store for everything else — a custom durable recorder opts into the durable path
 * by implementing it (or by setting the store key explicitly). Purely a capability declaration:
 * no methods, and implementing it does not change what the recorder itself must do.
 */
interface DurableEvidenceRecorder {}
