<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;

final class UnknownCapability extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self("The [{$name}] capability is not registered.");
    }
}
