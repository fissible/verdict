<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use LogicException;

final class CapabilityNotExecutable extends LogicException
{
    public static function named(string $name): self
    {
        return new self("The [{$name}] capability does not define a target-bound executor.");
    }
}
