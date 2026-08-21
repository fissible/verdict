<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use DateTimeImmutable;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseCapabilityConfigurationStore implements CapabilityConfigurationStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_capability_configurations',
    ) {}

    public function record(CapabilityConfiguration $configuration): void
    {
        // Artisan boots the application before dispatching any command — including `migrate`, the
        // command that creates this table — so boot-time registration must survive an unmigrated
        // database (#240). Skipping is safe because this store is a write-only audit trail: nothing
        // in the decision path reads it, so a skipped write cannot change an authorization outcome.
        // The next boot after migration records what this one skipped.
        if ($this->tableIsMissing()) {
            return;
        }

        // The fingerprint is the primary key. insertOrIgnore intentionally makes concurrent
        // registrations of the same immutable configuration a no-op: the first writer wins and
        // no later writer can rewrite the historical configuration.
        $this->connection->table($this->table)->insertOrIgnore([
            'configuration_fingerprint' => $configuration->fingerprint,
            'capability' => $configuration->capability,
            'configuration' => ArgumentFingerprint::canonicalJson($configuration->declared),
            'first_seen_at' => new DateTimeImmutable,
        ]);
    }

    private function tableIsMissing(): bool
    {
        // ConnectionInterface carries no schema builder; a substitute connection that is not a real
        // Illuminate Connection gets no guard and behaves exactly as before this check existed.
        return $this->connection instanceof Connection
            && ! $this->connection->getSchemaBuilder()->hasTable($this->table);
    }
}
