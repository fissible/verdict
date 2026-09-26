<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Internal signal raised inside the strict-issuance admission closure when the attest append
 * throws. It distinguishes a failed attestation — which the manager maps to
 * IssuanceRefused/AttestAppendFailed — from a genuine store/DB error propagating out of the same
 * transaction, so the latter is never misclassified as an attest failure. Not part of the
 * supported surface; it never escapes ApprovalManager::issue().
 */
final class AttestedIssuanceAppendFailed extends RuntimeException
{
    public static function from(Throwable $previous): self
    {
        return new self('Attesting the issued summary failed before the receipt was persisted.', 0, $previous);
    }
}
