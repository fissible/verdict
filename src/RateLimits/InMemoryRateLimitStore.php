<?php

declare(strict_types=1);

namespace Fissible\Verdict\RateLimits;

use DateTimeImmutable;
use Fissible\Verdict\Contracts\PrunableRateLimitStore;

/**
 * Process-local storage for tests and local development only.
 */
final class InMemoryRateLimitStore implements PrunableRateLimitStore
{
    /** @var array<string, array{attempts: int, reset_at: DateTimeImmutable}> */
    private array $buckets = [];

    public function consume(RateLimitConsumption $consumption): RateLimitOutcome
    {
        [$key, $resetAt] = $this->window($consumption);
        $bucket = $this->buckets[$key] ?? ['attempts' => 0, 'reset_at' => $resetAt];

        if ($bucket['attempts'] >= $consumption->limit) {
            return new RateLimitOutcome(false, $consumption->limit, 0, $bucket['reset_at']);
        }

        $bucket['attempts']++;
        $this->buckets[$key] = $bucket;

        return new RateLimitOutcome(
            allowed: true,
            limit: $consumption->limit,
            remaining: max(0, $consumption->limit - $bucket['attempts']),
            resetAt: $bucket['reset_at'],
        );
    }

    public function pruneExpired(DateTimeImmutable $before): int
    {
        $count = 0;

        foreach ($this->buckets as $key => $bucket) {
            if ($bucket['reset_at'] <= $before) {
                unset($this->buckets[$key]);
                $count++;
            }
        }

        return $count;
    }

    /** @return array{string, DateTimeImmutable} */
    private function window(RateLimitConsumption $consumption): array
    {
        $start = intdiv($consumption->at->getTimestamp(), $consumption->windowSeconds)
            * $consumption->windowSeconds;
        $resetAt = new DateTimeImmutable('@'.($start + $consumption->windowSeconds));

        return [$consumption->bucketFingerprint.':'.$start, $resetAt];
    }
}
