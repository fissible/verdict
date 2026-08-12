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

it('leaves the second attempt\'s concurrency error explicit rather than the first', function (): void {
    $connection = $this->createMock(ConnectionInterface::class);
    $first = new RuntimeException('Deadlock found when trying to get lock');
    $second = new RuntimeException('deadlock detected');
    $attempt = 0;

    $connection->expects($this->exactly(2))
        ->method('transaction')
        ->willReturnCallback(function () use (&$attempt, $first, $second): never {
            $attempt++;

            throw $attempt === 1 ? $first : $second;
        });

    $caught = null;

    try {
        TransactionRetry::run($connection, fn (): string => 'unreachable');
    } catch (Throwable $error) {
        $caught = $error;
    }

    // Distinct instances, so this pins the surviving exception by identity: a stale re-throw of
    // the first attempt's error would describe the wrong attempt to whoever triages the incident.
    expect($caught)->toBe($second);
});
