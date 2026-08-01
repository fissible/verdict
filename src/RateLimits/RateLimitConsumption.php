<?php

declare(strict_types=1);

namespace Fissible\Verdict\RateLimits;

use DateTimeImmutable;

final readonly class RateLimitConsumption
{
    public function __construct(
        public string $bucketFingerprint,
        public int $limit,
        public int $windowSeconds,
        public DateTimeImmutable $at,
    ) {}
}
