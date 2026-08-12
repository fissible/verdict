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

    $startedAt = hrtime(true);

    expect(TransactionRetry::run($connection, fn (): string => 'completed'))->toBe('completed');

    // The delay is the whole reason this wrapper exists rather than Laravel's own
    // `transaction($callback, 2)`: synchronized victims that retry immediately collide again
    // (ADR 0018, #92). Asserting the elapsed time keeps the `usleep()` from being deleted silently.
    expect((hrtime(true) - $startedAt) / 1_000_000)->toBeGreaterThanOrEqual(10.0);
});

it('does not retry an unrelated transaction error', function (): void {
    $connection = $this->createMock(ConnectionInterface::class);

    $connection->expects($this->once())
        ->method('transaction')
        ->willThrowException(new RuntimeException('Connection configuration is invalid'));

    TransactionRetry::run($connection, fn (): string => 'unreachable');
})->throws(RuntimeException::class, 'Connection configuration is invalid');

it('stops after three retries and surfaces the last attempt\'s concurrency error', function (): void {
    $connection = $this->createMock(ConnectionInterface::class);
    // Four distinct instances, and deliberately four different detector matches — only the first
    // two carry a 40001 SQLSTATE. `database is locked` is SQLite's, which the stores support.
    $errors = [
        new RuntimeException('Deadlock found when trying to get lock'),
        new RuntimeException('deadlock detected'),
        new RuntimeException('database is locked'),
        new RuntimeException('has been chosen as the deadlock victim'),
    ];
    $attempt = 0;

    $connection->expects($this->exactly(4))
        ->method('transaction')
        ->willReturnCallback(function () use (&$attempt, $errors): never {
            throw $errors[$attempt++];
        });

    $caught = null;

    try {
        TransactionRetry::run($connection, fn (): string => 'unreachable');
    } catch (Throwable $error) {
        $caught = $error;
    }

    // Pinned by identity rather than by message: a stale re-throw of an earlier attempt's error
    // would describe the wrong attempt to whoever triages the incident.
    expect($caught)->toBe($errors[3])
        ->and($attempt)->toBe(4);
});
