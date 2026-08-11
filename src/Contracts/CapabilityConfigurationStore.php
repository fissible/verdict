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
     */
    public function record(CapabilityConfiguration $configuration): void;
}
