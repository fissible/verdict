<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use DateTimeImmutable;
use Fissible\Verdict\Console\DatabaseTableStore;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

final class DatabaseCapabilityConfigurationStore implements CapabilityConfigurationStore, DatabaseTableStore
{
    private bool $tableKnownToExist = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'verdict_capability_configurations',
    ) {}

    public function record(CapabilityConfiguration $configuration): bool
    {
        // Artisan boots the application before dispatching any command — including `migrate`, the
        // command that creates this table — so boot-time registration must survive an unmigrated
        // database (#240). Skipping is safe because this store is a write-only audit trail: nothing
        // in the decision path reads it, so a skipped write cannot change an authorization outcome.
        // The next process to boot after migration records what this one skipped; a process that
        // booted pre-migration keeps its registrations unrecorded until it restarts, and
        // `verdict:validate` names the missing table so the gap stays visible in the meantime.
        try {
            if (! $this->hasTable()) {
                return false;
            }
        } catch (QueryException) {
            // A fresh clone boots before its SQLite file even exists (package:discover during
            // composer install, then key:generate) — an unreachable database defers exactly like a
            // missing table (#256). hasTable() itself keeps throwing so verdict:validate still
            // reports "could not inspect its table" for an unreachable database, a different
            // remedy than "missing table".
            return false;
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

        return true;
    }

    public function hasTable(): bool
    {
        // A table cannot un-migrate, so a positive answer is memoized and the schema-introspection
        // query costs one round-trip per store instance, not one per capability per boot.
        if ($this->tableKnownToExist) {
            return true;
        }

        // ConnectionInterface carries no schema builder; a substitute connection that is not a real
        // Illuminate Connection is assumed migrated and behaves exactly as before this guard existed.
        if (! $this->connection instanceof Connection) {
            return true;
        }

        return $this->tableKnownToExist = $this->connection->getSchemaBuilder()->hasTable($this->table);
    }

    public function table(): string
    {
        return $this->table;
    }
}
