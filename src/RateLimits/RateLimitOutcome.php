<?php

declare(strict_types=1);

namespace Fissible\Verdict\RateLimits;

use DateTimeImmutable;

final readonly class RateLimitOutcome
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public DateTimeImmutable $resetAt,
    ) {}
}
