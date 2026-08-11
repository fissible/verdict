<?php

declare(strict_types=1);

namespace Fissible\Verdict\Support;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
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
        }

        usleep(random_int(10_000, 50_000));

        return $connection->transaction($callback);
    }
}
