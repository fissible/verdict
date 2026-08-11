<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use DateTimeImmutable;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseCapabilityConfigurationStore implements CapabilityConfigurationStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_capability_configurations',
    ) {}

    public function record(Capability $capability): void
    {
        // The fingerprint is the primary key. insertOrIgnore intentionally makes concurrent
        // registrations of the same immutable configuration a no-op: the first writer wins and
        // no later writer can rewrite the historical configuration.
        $this->connection->table($this->table)->insertOrIgnore([
            'configuration_fingerprint' => $capability->configurationFingerprint(),
            'capability' => $capability->name,
            'configuration' => ArgumentFingerprint::canonicalJson($capability->declaredConfiguration()),
            'first_seen_at' => new DateTimeImmutable,
        ]);
    }
}
