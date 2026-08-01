<?php

declare(strict_types=1);

namespace Fissible\Verdict\Support;

use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Illuminate\Database\ConnectionInterface;

final class IndependentTransactionGuard
{
    public static function assertNoOuterTransaction(
        ConnectionInterface $connection,
        string $operation,
    ): void {
        $transactionLevel = $connection->transactionLevel();

        if ($transactionLevel === 0) {
            return;
        }

        throw UnsafeOuterTransaction::forStoreMutation($operation, $transactionLevel);
    }
}
