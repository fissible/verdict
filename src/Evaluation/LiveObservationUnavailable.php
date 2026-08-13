<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use RuntimeException;

final class LiveObservationUnavailable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("A live observation could not be assembled: {$reason}");
    }
}
