<?php

declare(strict_types=1);

use Fissible\Verdict\Support\TransactionRetry;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;

it('does not retry Laravel\'s nested-transaction deadlock signal', function (): void {
    $connection = app('db')->connection();
    $attempts = 0;

    $connection->beginTransaction();

    try {
        expect($connection->transactionLevel())->toBe(1);

        $caught = null;

        try {
            TransactionRetry::run($connection, function () use (&$attempts): never {
                $attempts++;

                throw new PDOException('Deadlock found when trying to get lock');
            });
        } catch (Throwable $error) {
            $caught = $error;
        }

        expect($caught)->toBeInstanceOf(DeadlockException::class)
            ->and($caught->getPrevious())->toBeInstanceOf(PDOException::class)
            ->and($attempts)->toBe(1);
    } finally {
        $connection->rollBack();
    }
});

it('releases a driver transaction Laravel\'s commit handler left open before retrying', function (): void {
    $real = app('db')->connection();

    // `Connection::handleCommitTransactionException()` rolls the PDO handle back only when it has
    // another attempt left, and Verdict always calls `transaction()` with Laravel's default single
    // attempt. A conflict raised at COMMIT therefore leaves the counter at zero with the handle
    // still open — reproduced here exactly, because no in-process fake can raise a real one.
    $connection = new class($real->getPdo(), $real->getDatabaseName(), $real->getTablePrefix(), $real->getConfig()) extends Connection
    {
        public int $attempts = 0;

        public function transaction(Closure $callback, $attempts = 1)
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                $this->getPdo()->beginTransaction();
                $this->transactions = 0;

                throw new PDOException('SQLSTATE[40001]: Serialization failure: deadlock detected', 40001);
            }

            return parent::transaction($callback, $attempts);
        }
    };

    // Without the release, the retry's `beginTransaction()` raises "There is already an active
    // transaction" and the caller never sees either the retried result or the honest conflict.
    expect(TransactionRetry::run($connection, fn (): string => 'completed'))->toBe('completed')
        ->and($connection->attempts)->toBe(2)
        ->and($connection->getPdo()->inTransaction())->toBeFalse();
});
