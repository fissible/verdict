<?php

declare(strict_types=1);

namespace Fissible\Verdict\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;

final class SecurityStateTransaction
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(ConnectionInterface $connection, string $operation, Closure $callback): mixed
    {
        IndependentTransactionGuard::assertNoOuterTransaction($connection, $operation);

        return TransactionRetry::run($connection, $callback);
    }
}
