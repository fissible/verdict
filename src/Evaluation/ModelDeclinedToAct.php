<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use RuntimeException;

final class ModelDeclinedToAct extends RuntimeException
{
    public static function forCase(string $caseId): self
    {
        return new self("The model completed [{$caseId}] without invoking a bound tool.");
    }
}
