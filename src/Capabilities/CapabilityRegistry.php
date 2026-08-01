<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Fissible\Verdict\Exceptions\CapabilityAlreadyRegistered;
use Fissible\Verdict\Exceptions\UnknownCapability;

final class CapabilityRegistry
{
    /**
     * @var array<string, Capability>
     */
    private array $capabilities = [];

    public function register(Capability $capability): self
    {
        if ($this->has($capability->name)) {
            throw CapabilityAlreadyRegistered::named($capability->name);
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
