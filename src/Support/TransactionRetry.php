<?php

declare(strict_types=1);

namespace Fissible\Verdict\Support;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Throwable;

final class TransactionRetry
{
    /**
     * Retry a Verdict-owned transaction once after a randomized delay.
     *
     * Laravel's built-in transaction retry immediately re-runs every deadlock
     * victim. Under a synchronized first-insert race, those victims can collide
     * again. The delay spreads their single permitted retry without retrying an
     * application-owned outer transaction; callers enforce that boundary first.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws Throwable
     */
    public static function run(ConnectionInterface $connection, Closure $callback): mixed
    {
        $container = Container::getInstance();
        $detector = $container->bound(ConcurrencyErrorDetectorContract::class)
            ? $container[ConcurrencyErrorDetectorContract::class]
            : new ConcurrencyErrorDetector;

        try {
            return $connection->transaction($callback);
        } catch (Throwable $error) {
            // Laravel uses this exception to stop retrying a deadlock from a nested transaction.
            // It may still match the detector by message, but belongs to the outer transaction.
            if ($error instanceof DeadlockException || ! $detector->causedByConcurrencyError($error)) {
                throw $error;
            }

            if (! self::releasedDriverTransaction($connection)) {
                throw $error;
            }
        }

        usleep(random_int(10_000, 50_000));

        return $connection->transaction($callback);
    }

    /**
     * Release a driver-level transaction Laravel's commit handler left open, reporting success.
     *
     * A conflict raised at COMMIT — how PostgreSQL normally reports a read-write serialization
     * failure — reaches `Connection::handleCommitTransactionException()`, which rolls the PDO
     * handle back only when it has another attempt left. Verdict calls `transaction()` with
     * Laravel's default single attempt, so that branch never runs: the connection's transaction
     * counter reaches zero while the handle can still be mid-transaction. Retrying then fails on
     * `beginTransaction()` with "There is already an active transaction" instead of either
     * retrying or surfacing the conflict. Returning false leaves the caller the honest conflict.
     */
    private static function releasedDriverTransaction(ConnectionInterface $connection): bool
    {
        if (! $connection instanceof Connection) {
            return true;
        }

        $pdo = $connection->getPdo();

        if (! $pdo->inTransaction()) {
            return true;
        }

        try {
            $pdo->rollBack();
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
