<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;

final class ReviewRequestNotIssued extends RuntimeException
{
    public static function forCapability(string $name): self
    {
        return new self("The [{$name}] capability requires asynchronous review, but its configured review lane could not issue a request.");
    }
}
