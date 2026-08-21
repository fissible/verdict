<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Capabilities\CapabilityConfiguration;

/**
 * @experimental The configuration-registry storage contract may change before Verdict 1.0.
 */
interface CapabilityConfigurationStore
{
    /**
     * Persist this closure-free, immutable, content-addressed configuration if it is not present.
     *
     * @return bool whether the store handled the configuration — true when it was written or is
     *              deliberately not persisted by design; false when the write was skipped and may
     *              be retried later (for example, before the backing table has been migrated).
     */
    public function record(CapabilityConfiguration $configuration): bool;
}
