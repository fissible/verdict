<?php

declare(strict_types=1);

use Fissible\Verdict\Support\TransactionRetry;
use Illuminate\Database\ConnectionInterface;

it('retries one recognized concurrency error after a randomized delay', function (): void {
    $connection = $this->createMock(ConnectionInterface::class);
    $attempt = 0;

    $connection->expects($this->exactly(2))
        ->method('transaction')
        ->willReturnCallback(function (Closure $callback) use (&$attempt): string {
            $attempt++;

            if ($attempt === 1) {
                throw new RuntimeException('Deadlock found when trying to get lock');
            }

            return $callback();
        });

    expect(TransactionRetry::run($connection, fn (): string => 'completed'))->toBe('completed');
});

it('does not retry an unrelated transaction error', function (): void {
    $connection = $this->createMock(ConnectionInterface::class);

    $connection->expects($this->once())
        ->method('transaction')
        ->willThrowException(new RuntimeException('Connection configuration is invalid'));

    TransactionRetry::run($connection, fn (): string => 'unreachable');
})->throws(RuntimeException::class, 'Connection configuration is invalid');

it('stops after three retries for a recognized concurrency error', function (): void {
    $connection = $this->createMock(ConnectionInterface::class);

    $connection->expects($this->exactly(4))
        ->method('transaction')
        ->willThrowException(new RuntimeException('Deadlock found when trying to get lock'));

    TransactionRetry::run($connection, fn (): string => 'unreachable');
})->throws(RuntimeException::class, 'Deadlock found when trying to get lock');
