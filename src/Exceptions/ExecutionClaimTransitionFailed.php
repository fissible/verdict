<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use Fissible\Verdict\ExecutionClaims\ExecutionClaimOutcome;
use RuntimeException;

/**
 * A claim could not be moved out of the claimed state.
 *
 * This is a concurrency event, not a programming error: the usual cause is an operator resolving
 * the claim through verdict:resolve-execution-claim while its executor is still running. It is
 * deliberately not a LogicException, which conventionally means a defect in the calling code.
 */
final class ExecutionClaimTransitionFailed extends RuntimeException
{
    private function __construct(
        public readonly string $claimId,
        public readonly ExecutionClaimOutcome $outcome,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function completing(string $claimId, ExecutionClaimOutcome $outcome): self
    {
        return new self(
            $claimId,
            $outcome,
            "The execution claim [{$claimId}] could not be marked completed; the store reported [{$outcome->value}].",
        );
    }

    public static function markingIndeterminate(string $claimId, ExecutionClaimOutcome $outcome): self
    {
        return new self(
            $claimId,
            $outcome,
            "The execution claim [{$claimId}] could not be marked indeterminate; the store reported [{$outcome->value}].",
        );
    }
}
