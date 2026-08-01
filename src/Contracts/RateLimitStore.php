<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\RateLimits\RateLimitConsumption;
use Fissible\Verdict\RateLimits\RateLimitOutcome;

interface RateLimitStore
{
    public function consume(RateLimitConsumption $consumption): RateLimitOutcome;
}
