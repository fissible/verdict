<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

trait AttackPackConfigValidation
{
    private function requireNonEmptyString(string $value, string $message): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($message);
        }
    }

    private function requireIdentifier(string|int $value, string $message): void
    {
        if (is_string($value) && trim($value) === '') {
            throw new InvalidArgumentException($message);
        }
    }
}
