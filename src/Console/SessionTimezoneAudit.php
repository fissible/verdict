<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console;

use Fissible\Verdict\Contracts\ActionIntentStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * @internal
 */
final class SessionTimezoneAudit
{
    public static function rejects(string $driver, ?string $sessionTimeZone): bool
    {
        if (! in_array(strtolower($driver), ['mysql', 'mariadb'], true)) {
            return false;
        }

        return ! in_array(strtolower((string) $sessionTimeZone), ['+00:00', 'utc'], true);
    }

    /** @return array<string, Connection> */
    public static function auditable(Container $container): array
    {
        /** @var array<string, Connection> $connections */
        $connections = [];
        $database = $container->make(DatabaseManager::class);

        foreach ([
            ApprovalReceiptStore::class,
            RateLimitStore::class,
            ExecutionClaimStore::class,
            ActionIntentStore::class,
            CapabilityConfigurationStore::class,
        ] as $contract) {
            try {
                $store = $container->make($contract);
            } catch (Throwable) {
                // ValidateVerdictCommand reports an unresolvable required store. It must not
                // prevent the independently configured stores from being inspected here.
                continue;
            }

            if ($store instanceof DatabaseTableStore) {
                self::add($connections, $store->connection());
            }
        }

        $recorder = config('verdict.evidence.recorder');
        $writer = config('verdict.evidence.writer');
        $ledger = config('verdict.evidence.ledger');

        foreach ([$writer ?? $recorder, $ledger ?? $recorder] as $role) {
            if ($role === DatabaseEvidenceRecorder::class) {
                self::add($connections, self::evidenceConnection($database, 'verdict.evidence.connection'));
            }

            if ($role === AttestEvidenceRecorder::class) {
                self::add($connections, self::evidenceConnection($database, 'verdict.evidence.attest.fallback_connection'));
            }
        }

        return $connections;
    }

    /** @param array<string, Connection> $connections */
    private static function add(array &$connections, ConnectionInterface $connection): void
    {
        // The probe needs Laravel's concrete connection API — a name to report and a driver to
        // decide on — so a custom ConnectionInterface is skipped rather than guessed at. That
        // cannot produce a silently clean validation: a built-in store on such a connection
        // already fails this command's table audit, which is where the incompatibility surfaces.
        if (! $connection instanceof Connection) {
            return;
        }

        $name = $connection->getName();

        if (! is_string($name) || $name === '') {
            return;
        }

        $connections[$name] = $connection;
    }

    private static function evidenceConnection(DatabaseManager $database, string $configuration): ConnectionInterface
    {
        $name = config($configuration);

        return $database->connection(is_string($name) ? $name : null);
    }
}
