<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use InvalidArgumentException;

/**
 * The one canonicalization every Verdict fingerprint is computed over.
 *
 * Two inputs that canonicalize identically are treated as the same authorized request — an approval
 * receipt, an execution claim, and a rate-limit bucket all decide identity this way — so what may
 * enter one is a security decision rather than an encoding detail. See
 * docs/adr/0013-authorization-binding-layers.md.
 *
 * The contract: scalars, null, and arrays of those. Object keys are sorted so key order does not
 * change identity; list order does, because a reordered list is a different request. String values
 * are byte-exact and deliberately not Unicode-normalized.
 */
final class CanonicalJson
{
    public static function encode(mixed $value, string $label): string
    {
        $normalized = self::normalize($value, $label);

        // json_encode renders floats according to serialize_precision, so the same value
        // fingerprints differently across deployments that set it differently — and across one
        // deployment either side of an ini change, which leaves an already-issued approval
        // impossible to consume. Pinned to PHP's own default rather than to a fixed digit count, so
        // that deployments on the default (every deployment since PHP 7.1 that has not changed it)
        // keep the digests they have already persisted.
        $caller = ini_get('serialize_precision');
        $pinned = $caller !== '-1';

        if ($pinned) {
            ini_set('serialize_precision', '-1');
        }

        try {
            return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } finally {
            if ($pinned && is_string($caller)) {
                ini_set('serialize_precision', $caller);
            }
        }
    }

    private static function normalize(mixed $value, string $label): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                '%s must be a scalar, null, or an array of those values; [%s] cannot be canonicalized. '
                .'An object is fingerprinted by whatever json_encode emits for it: JsonSerializable puts an '
                .'application-defined method inside the computation, non-public properties are dropped '
                .'silently, and the result collides with a plain array of the same shape. Convert it to an '
                .'array of scalars at the point you supply it.',
                $label,
                get_debug_type($value),
            ));
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn (mixed $item): mixed => self::normalize($item, $label), $value);
    }
}
