<?php

declare(strict_types=1);

use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Fissible\Verdict\Support\SecurityStateTransaction;
use Fissible\Verdict\Support\TransactionRetry;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
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
    // (ADR 0018, #92). Asserting the elapsed time keeps the `usleep()` from being deleted silently —
    // a deleted sleep measures ~0ms. The floor is deliberately well under the 10ms minimum, not equal
    // to it: neither `usleep()` nor `hrtime()` guarantees the full requested duration elapses, and a
    // coarse OS timer (observed on Windows + PHP 8.5) measured 9.9779ms for a 10ms sleep — a `>= 10.0`
    // assertion failed there for no real defect. 5ms still unambiguously proves the sleep ran.
    expect((hrtime(true) - $startedAt) / 1_000_000)->toBeGreaterThanOrEqual(5.0);
});

it('does not retry an unrelated transaction error', function (): void {
    $connection = $this->createMock(ConnectionInterface::class);

    $connection->expects($this->once())
        ->method('transaction')
        ->willThrowException(new RuntimeException('Connection configuration is invalid'));

    TransactionRetry::run($connection, fn (): string => 'unreachable');
})->throws(RuntimeException::class, 'Connection configuration is invalid');

it('uses the application-bound concurrency detector', function (): void {
    $previousContainer = Container::getInstance();
    $container = new Container;
    $detector = new class implements ConcurrencyErrorDetector
    {
        public int $calls = 0;

        public function causedByConcurrencyError(Throwable $exception): bool
        {
            $this->calls++;

            return $exception->getMessage() === 'application-specific conflict';
        }
    };
    $container->instance(ConcurrencyErrorDetector::class, $detector);
    Container::setInstance($container);

    $connection = $this->createMock(ConnectionInterface::class);
    $attempt = 0;
    $connection->expects($this->exactly(2))
        ->method('transaction')
        ->willReturnCallback(function (Closure $callback) use (&$attempt): string {
            $attempt++;

            if ($attempt === 1) {
                throw new RuntimeException('application-specific conflict');
            }

            return $callback();
        });

    try {
        expect(TransactionRetry::run($connection, fn (): string => 'completed'))->toBe('completed')
            ->and($detector->calls)->toBe(1);
    } finally {
        Container::setInstance($previousContainer);
    }
});

it('retries Laravel-recognized SQLite lock messages', function (string $message): void {
    $connection = $this->createMock(ConnectionInterface::class);
    $attempt = 0;
    $connection->expects($this->exactly(2))
        ->method('transaction')
        ->willReturnCallback(function (Closure $callback) use (&$attempt, $message): string {
            $attempt++;

            if ($attempt === 1) {
                throw new RuntimeException($message);
            }

            return $callback();
        });

    expect(TransactionRetry::run($connection, fn (): string => 'completed'))->toBe('completed');
})->with([
    'database is locked' => 'database is locked',
    'database table is locked' => 'database table is locked: verdict_execution_claims',
]);

it('stops after the bounded MySQL lock-wait retry budget and surfaces the final error', function (): void {
    $connection = $this->createMock(ConnectionInterface::class);
    $error = new RuntimeException('Lock wait timeout exceeded; try restarting transaction');

    $connection->expects($this->exactly(4))
        ->method('transaction')
        ->willThrowException($error);

    expect(fn () => TransactionRetry::run($connection, fn (): string => 'unreachable'))
        ->toThrow($error);
});

it('does not retry a security-state mutation inside an application-owned transaction', function (): void {
    $connection = $this->createMock(ConnectionInterface::class);
    $connection->expects($this->once())
        ->method('transactionLevel')
        ->willReturn(1);
    $connection->expects($this->never())->method('transaction');

    expect(fn () => SecurityStateTransaction::run($connection, 'transition an execution claim', fn (): string => 'unreachable'))
        ->toThrow(UnsafeOuterTransaction::class, 'transition an execution claim');
});

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
