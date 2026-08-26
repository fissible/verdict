<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Contracts\ProvidesVerdictIdentity;
use InvalidArgumentException;

/**
 * The one derivation of an identity fingerprint from an application-supplied context value —
 * shared by decision evidence and the write-ahead intent record so the two layers can never
 * disagree about who an actor fingerprint names.
 */
final class IdentityFingerprint
{
    public static function for(mixed $identity): ?string
    {
        if (! $identity instanceof ProvidesVerdictIdentity) {
            return null;
        }

        $canonicalIdentity = $identity->verdictIdentity();

        if ($canonicalIdentity === '') {
            throw new InvalidArgumentException('Verdict identities must not be empty.');
        }

        return hash('sha256', $canonicalIdentity);
    }
}
