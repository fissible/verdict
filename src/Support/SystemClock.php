<?php

declare(strict_types=1);

namespace Fissible\Verdict\Support;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
