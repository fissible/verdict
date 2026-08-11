<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Capabilities\Capability;

/**
 * @experimental The configuration-registry storage contract may change before Verdict 1.0.
 */
interface CapabilityConfigurationStore
{
    /**
     * Persist this immutable, content-addressed capability configuration if it is not present.
     */
    public function record(Capability $capability): void;
}
