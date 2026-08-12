<?php

declare(strict_types=1);

namespace Fissible\Verdict\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Throwable;

final class TransactionRetry
{
    /**
     * Run a durable security-state mutation in a transaction Verdict owns, retried once.
     *
     * ADR 0004 requires every such mutation to refuse an application-owned outer transaction, and
     * ADR 0018 requires it to retry one recognized conflict. The two belong together: refusing the
     * outer transaction is what makes retrying safe, because a conflict raised inside is by
     * construction Verdict's own. Pairing them in one call keeps a new mutation from acquiring
     * half of the pattern — stores must call this rather than {@see self::run()} directly.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws Throwable
     */
    public static function runIndependently(
        ConnectionInterface $connection,
        string $operation,
        Closure $callback,
    ): mixed {
        IndependentTransactionGuard::assertNoOuterTransaction($connection, $operation);

        return self::run($connection, $callback);
    }

    /**
     * Retry a Verdict-owned transaction once after a randomized delay.
     *
     * Laravel's built-in transaction retry immediately re-runs every deadlock victim. Under a
     * synchronized first-insert race, those victims can collide again. The delay spreads their
     * single permitted retry without retrying an application-owned outer transaction; callers
     * enforce that boundary first.
     *
     * A retried attempt re-executes against the now-committed state, so its outcome — including a
     * transition another caller already won — is the honest answer rather than a stale one. The
     * exception a second failure raises is surfaced as-is: `issue()` and `claim()` catch
     * `UniqueConstraintViolationException` from this method by type, so wrapping it to chain the
     * first attempt's error as `previous` would break their recovery.
     *
     * @internal Stores must call {@see self::runIndependently()}, which pairs this with ADR 0004's
     * guard. This entry point exists for the nested-transaction case, which the guard forbids in
     * production and which is therefore only reachable from tests.
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
        try {
            return $connection->transaction($callback);
        } catch (Throwable $error) {
            // Laravel uses this exception to stop retrying a deadlock from a nested transaction.
            // It may still match the detector by message, but belongs to the outer transaction.
            if ($error instanceof DeadlockException || ! self::hasRetryableSqlState($error)) {
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
     * Retry only database-declared serialization conflicts and deadlocks.
     *
     * Laravel's broad detector also recognizes MySQL's lock-wait timeout (1205). That timeout
     * means a caller may already have waited up to the server's configured lock-wait limit; a
     * second attempt would double synchronous protected-action latency without being the
     * short-lived serialization conflict this bounded retry is intended to resolve.
     */
    private static function hasRetryableSqlState(Throwable $error): bool
    {
        for ($exception = $error; $exception !== null; $exception = $exception->getPrevious()) {
            if (in_array((string) $exception->getCode(), ['40001', '40P01'], true)) {
                return true;
            }
        }

        return false;
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
