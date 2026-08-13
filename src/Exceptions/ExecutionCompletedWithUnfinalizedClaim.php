<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The executor succeeded, and its at-most-once claim could not be finalized afterwards.
 *
 * The side effect happened. This exception exists so a caller cannot mistake that for a failed
 * execution — the distinction ExecutionClaimFinalizationFailed makes for the opposite case, where
 * the executor itself failed.
 *
 * $output carries the executor's result, because the caller is the only party holding it who can
 * reconcile. $claimId names the claim to pass to verdict:resolve-execution-claim, so an orphaned
 * claim is a one-command fix rather than a search.
 */
final class ExecutionCompletedWithUnfinalizedClaim extends RuntimeException
{
    private function __construct(
        public readonly mixed $output,
        public readonly ?string $claimId,
        Throwable $finalizationFailure,
    ) {
        parent::__construct(
            $claimId === null
                ? 'The executor succeeded but its at-most-once claim could not be finalized.'
                : "The executor succeeded but its at-most-once claim [{$claimId}] could not be finalized. "
                    .'The side effect has already happened; reconcile the claim with verdict:resolve-execution-claim.',
            previous: $finalizationFailure,
        );
    }

    public static function fromFailure(mixed $output, ?string $claimId, Throwable $finalizationFailure): self
    {
        return new self($output, $claimId, $finalizationFailure);
    }
}
