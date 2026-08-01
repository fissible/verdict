<?php

declare(strict_types=1);

namespace Fissible\Verdict\RateLimits;

use DateTimeImmutable;
use Fissible\Verdict\Contracts\PrunableRateLimitStore;
use Fissible\Verdict\Support\IndependentTransactionGuard;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use stdClass;

final readonly class DatabaseRateLimitStore implements PrunableRateLimitStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_rate_limit_buckets',
    ) {}

    public function consume(RateLimitConsumption $consumption): RateLimitOutcome
    {
        IndependentTransactionGuard::assertNoOuterTransaction($this->connection, 'consume a semantic rate-limit unit');

        [$windowStartsAt, $resetAt] = $this->window($consumption);

        try {
            return $this->connection->transaction(
                fn (): RateLimitOutcome => $this->consumeLocked($consumption, $windowStartsAt, $resetAt, true),
            );
        } catch (UniqueConstraintViolationException) {
            // Another transaction created the first bucket concurrently. Retry the actual
            // consume operation so this caller is counted rather than merely reading its row.
            return $this->connection->transaction(
                fn (): RateLimitOutcome => $this->consumeLocked($consumption, $windowStartsAt, $resetAt, false),
            );
        }
    }

    public function pruneExpired(DateTimeImmutable $before): int
    {
        return $this->connection->table($this->table)
            ->where('reset_at', '<=', $before)
            ->delete();
    }

    private function consumeLocked(
        RateLimitConsumption $consumption,
        DateTimeImmutable $windowStartsAt,
        DateTimeImmutable $resetAt,
        bool $mayInsert,
    ): RateLimitOutcome {
        $row = $this->connection->table($this->table)
            ->where('bucket_fingerprint', $consumption->bucketFingerprint)
            ->where('window_starts_at', $windowStartsAt)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof stdClass) {
            if (! $mayInsert) {
                throw new LogicException('A concurrently created rate-limit bucket could not be read.');
            }

            $this->connection->table($this->table)->insert([
                'bucket_fingerprint' => $consumption->bucketFingerprint,
                'window_starts_at' => $windowStartsAt,
                'reset_at' => $resetAt,
                'attempts' => 1,
                'created_at' => $consumption->at,
                'updated_at' => $consumption->at,
            ]);

            return new RateLimitOutcome(
                allowed: true,
                limit: $consumption->limit,
                remaining: max(0, $consumption->limit - 1),
                resetAt: $resetAt,
            );
        }

        $attempts = (int) $row->attempts;

        if ($attempts >= $consumption->limit) {
            return new RateLimitOutcome(false, $consumption->limit, 0, $resetAt);
        }

        $attempts++;
        $this->connection->table($this->table)
            ->where('bucket_fingerprint', $consumption->bucketFingerprint)
            ->where('window_starts_at', $windowStartsAt)
            ->update([
                'attempts' => $attempts,
                'updated_at' => $consumption->at,
            ]);

        return new RateLimitOutcome(
            allowed: true,
            limit: $consumption->limit,
            remaining: max(0, $consumption->limit - $attempts),
            resetAt: $resetAt,
        );
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    private function window(RateLimitConsumption $consumption): array
    {
        $start = intdiv($consumption->at->getTimestamp(), $consumption->windowSeconds)
            * $consumption->windowSeconds;

        return [
            new DateTimeImmutable('@'.$start),
            new DateTimeImmutable('@'.($start + $consumption->windowSeconds)),
        ];
    }
}
