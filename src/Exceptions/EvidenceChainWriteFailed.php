<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;
use Throwable;

final class EvidenceChainWriteFailed extends RuntimeException
{
    private function __construct(string $message, ?Throwable $previous)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function fromFailure(string $chainId, string $recordType, int $attempts, ?Throwable $previous): self
    {
        return new self(
            "Failed to write [{$recordType}] evidence to attest chain [{$chainId}] after {$attempts} attempt(s).",
            $previous,
        );
    }
}
