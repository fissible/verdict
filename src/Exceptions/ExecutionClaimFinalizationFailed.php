<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;
use Throwable;

final class ExecutionClaimFinalizationFailed extends RuntimeException
{
    private function __construct(
        public readonly Throwable $finalizationFailure,
        Throwable $executionFailure,
    ) {
        parent::__construct(
            'The executor failed and its at-most-once claim could not be marked indeterminate.',
            previous: $executionFailure,
        );
    }

    public static function fromFailures(Throwable $executionFailure, Throwable $finalizationFailure): self
    {
        return new self($finalizationFailure, $executionFailure);
    }
}
