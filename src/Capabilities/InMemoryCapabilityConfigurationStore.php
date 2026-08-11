<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Fissible\Verdict\Contracts\CapabilityConfigurationStore;

/** Process-local store for tests and local development only. */
final class InMemoryCapabilityConfigurationStore implements CapabilityConfigurationStore
{
    /** @var array<string, array{capability: string, configuration: array<string, mixed>}> */
    private array $configurations = [];

    public function record(CapabilityConfiguration $configuration): void
    {
        $this->configurations[$configuration->fingerprint] ??= [
            'capability' => $configuration->capability,
            'configuration' => $configuration->declared,
        ];
    }

    /** @return array<string, array{capability: string, configuration: array<string, mixed>}> */
    public function all(): array
    {
        return $this->configurations;
    }
}
