<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A classified tool returned a result that cannot be canonicalized into a provenance fingerprint —
 * an object, resource, or any non-scalar leaf. Recording provenance from it is impossible, so the
 * turn is halted (fail-closed): a consequential action must not proceed on a result Verdict cannot
 * attribute.
 *
 * This exists only to relocate the failure. Without it the halt surfaces as an opaque
 * "Provenance content ... cannot be canonicalized" error from deep inside CanonicalJson, naming the
 * value's type but not which tool produced it. This names the tool and the invocation and chains the
 * canonicalization error as its cause, so the operator can find the offending tool.
 */
final class ToolResultProvenanceUnrecordable extends RuntimeException
{
    public static function forTool(string $toolClass, string $invocationId, Throwable $previous): self
    {
        return new self(
            "Tool [{$toolClass}] (invocation [{$invocationId}]) returned a result that cannot be recorded "
            .'as provenance: a tool result must be scalar, null, or an array of those values. The turn was '
            .'halted because Verdict could not attribute the result. Underlying error: '.$previous->getMessage(),
            previous: $previous,
        );
    }
}
