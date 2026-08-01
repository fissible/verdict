<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use DateTimeImmutable;

interface PrunableRateLimitStore extends RateLimitStore
{
    public function pruneExpired(DateTimeImmutable $before): int;
}
