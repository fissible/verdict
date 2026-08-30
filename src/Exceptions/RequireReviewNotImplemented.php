<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use LogicException;

final class RequireReviewNotImplemented extends LogicException
{
    public static function forCapability(string $name): self
    {
        return new self("The [{$name}] capability requires asynchronous review, which is not implemented.");
    }
}
