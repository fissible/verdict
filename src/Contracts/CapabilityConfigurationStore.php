<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Capabilities\Capability;

interface CapabilityConfigurationStore
{
    /**
     * Persist this immutable, content-addressed capability configuration if it is not present.
     */
    public function record(Capability $capability): void;
}
