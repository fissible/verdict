<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Exceptions\CapabilityAlreadyRegistered;
use Fissible\Verdict\Exceptions\UnknownCapability;

final class CapabilityRegistry
{
    /**
     * @var array<string, Capability>
     */
    private array $capabilities = [];

    /** @var array<string, true> */
    private array $recordedFingerprints = [];

    public function __construct(
        private CapabilityConfigurationStore $configurations = new InMemoryCapabilityConfigurationStore,
    ) {}

    public function register(Capability $capability): self
    {
        if ($this->has($capability->name)) {
            throw CapabilityAlreadyRegistered::named($capability->name);
        }

        $fingerprint = $capability->configurationFingerprint();

        // Memoized only when the store reports the configuration handled: a store that skipped the
        // write (an unmigrated table, #240) leaves the fingerprint unmemoized, so any future
        // in-process registration path may retry. Registration currently runs once per process, so
        // the durable heal is the next boot after migration — validate names the gap until then.
        if (! isset($this->recordedFingerprints[$fingerprint]) && $this->configurations->record($capability->configuration())) {
            $this->recordedFingerprints[$fingerprint] = true;
        }

        $this->capabilities[$capability->name] = $capability;

        return $this;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->capabilities);
    }

    public function get(string $name): Capability
    {
        return $this->capabilities[$name] ?? throw UnknownCapability::named($name);
    }

    /**
     * @return array<string, Capability>
     */
    public function all(): array
    {
        return $this->capabilities;
    }
}
