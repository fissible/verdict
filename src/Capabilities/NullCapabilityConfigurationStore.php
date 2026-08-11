<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Fissible\Verdict\Contracts\CapabilityConfigurationStore;

/**
 * No-op registry for applications that do not retain durable evidence.
 */
final class NullCapabilityConfigurationStore implements CapabilityConfigurationStore
{
    public function record(Capability $capability): void {}
}
