<?php

declare(strict_types=1);

namespace Fissible\Verdict\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final class BindingAdmission
{
    public static function lockKey(string $toolCallId, string $bindingFingerprint): int
    {
        return unpack('J', substr(hash('sha256', hash('sha256', $toolCallId).hash('sha256', $bindingFingerprint), true), 0, 8))[1]
            ?? throw new LogicException('Unable to unpack the binding-admission lock key.');
    }

    /**
     * Acquire inside an open transaction owned by the caller.
     */
    public static function acquire(
        ConnectionInterface $connection,
        string $toolCallId,
        string $bindingFingerprint,
    ): void {
        if (! $connection instanceof Connection) {
            return;
        }

        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $connection->select('SELECT pg_advisory_xact_lock(?)', [self::lockKey($toolCallId, $bindingFingerprint)]);

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $table = (string) config('verdict.approvals.binding_admission_locks_table', 'verdict_binding_admission_locks');
            $lockKey = self::lockKey($toolCallId, $bindingFingerprint);

            $connection->statement("INSERT INTO {$table} (lock_key) VALUES (?) ON DUPLICATE KEY UPDATE lock_key = lock_key", [$lockKey]);
            $connection->select("SELECT lock_key FROM {$table} WHERE lock_key = ? FOR UPDATE", [$lockKey]);
        }
    }
}
