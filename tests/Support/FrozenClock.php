<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Contracts\Clock;

/**
 * A clock tests control. Frozen by default; `$stepSeconds` advances it on every read so a test can
 * march a scenario across a boundary it has no other hook into (a fixed rate-limit window, a TTL).
 */
final class FrozenClock implements Clock
{
    public DateTimeImmutable $time;

    public int $stepSeconds = 0;

    public function __construct(string $time = '2026-08-01 12:00:15')
    {
        $this->time = new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        $now = $this->time;

        if ($this->stepSeconds !== 0) {
            $this->time = $this->time->modify("+{$this->stepSeconds} seconds");
        }

        return $now;
    }
}
