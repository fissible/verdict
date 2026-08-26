<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Capabilities\Events\CapabilityConfigurationUnrecorded;
use Fissible\Verdict\Console\DatabaseTableStore;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;

final class DatabaseCapabilityConfigurationStore implements CapabilityConfigurationStore, DatabaseTableStore
{
    private bool $tableKnownToExist = false;

    private bool $databaseKnownUnreachable = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'verdict_capability_configurations',
        private readonly ?Dispatcher $events = null,
    ) {}

    public function record(CapabilityConfiguration $configuration): bool
    {
        // Once introspection has failed, further attempts this boot would each pay a fresh
        // connection timeout — one skip already fired the event, so the rest stay quiet.
        if ($this->databaseKnownUnreachable) {
            return false;
        }

        // Artisan boots the application before dispatching any command — including `migrate`, the
        // command that creates this table — so boot-time registration must survive an unmigrated
        // database (#240). Skipping is safe because this store is a write-only audit trail: nothing
        // in the decision path reads it, so a skipped write cannot change an authorization outcome.
        // The next process to boot after migration records what this one skipped, and
        // `verdict:validate` names a missing table so the gap stays visible in the meantime.
        try {
            if (! $this->hasTable()) {
                return false;
            }
        } catch (QueryException|LostConnectionException $exception) {
            // A fresh clone boots before its SQLite file even exists (package:discover during
            // composer install, then key:generate) — an unreachable database defers exactly like a
            // missing table (#256). hasTable() itself keeps throwing so verdict:validate still
            // reports "could not inspect its table", a different remedy than "missing table". The
            // same skip fires for a permanently misconfigured connection, so it is announced rather
            // than silent — once per store, since every later capability would fail identically.
            $this->databaseKnownUnreachable = true;
            $this->announceUnrecorded($configuration, $exception->getMessage());

            return false;
        }

        try {
            // The fingerprint is the primary key. insertOrIgnore intentionally makes concurrent
            // registrations of the same immutable configuration a no-op: the first writer wins and
            // no later writer can rewrite the historical configuration.
            $this->connection->table($this->table)->insertOrIgnore([
                'configuration_fingerprint' => $configuration->fingerprint,
                'capability' => $configuration->capability,
                'configuration' => ArgumentFingerprint::canonicalJson($configuration->declared),
                // This timezone-naive column is formatted in the object's zone by Laravel bindings, so mint UTC.
                'first_seen_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ]);
        } catch (QueryException|LostConnectionException $exception) {
            // A failing write (read-only filesystem, full disk, a schema the host has not migrated
            // to) is the same class of boot hazard as the missing table, one statement later, and
            // the same rationale holds: the skipped audit write cannot change an authorization
            // outcome, so it must not take boot down. Announced per configuration — unlike the
            // unreachable case, writes can fail for row-specific reasons. Caveat: catching inside a
            // caller-managed PostgreSQL transaction leaves that transaction aborted; registration
            // is a boot-time path and documented to run outside one.
            $this->announceUnrecorded($configuration, $exception->getMessage());

            return false;
        }

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

    private function announceUnrecorded(CapabilityConfiguration $configuration, string $reason): void
    {
        $this->events?->dispatch(new CapabilityConfigurationUnrecorded(
            capability: $configuration->capability,
            configurationFingerprint: $configuration->fingerprint,
            reason: $reason,
        ));
    }
}
