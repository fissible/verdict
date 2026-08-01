<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;
use Throwable;

final class TargetNotResolvable extends RuntimeException
{
    public const string DECISION_REASON = 'Capability target could not be resolved.';

    public static function make(?Throwable $previous = null): self
    {
        return new self(self::DECISION_REASON, previous: $previous);
    }
}
