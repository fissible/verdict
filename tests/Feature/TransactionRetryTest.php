<?php

declare(strict_types=1);

use Fissible\Verdict\Support\TransactionRetry;
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
