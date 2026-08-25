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
        return self::encodeNormalized(self::normalize($value, $label));
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

    private static function encodeNormalized(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return self::floatToken($value);
        }

        if (is_string($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (array_is_list($value)) {
            return '['.implode(',', array_map(self::encodeNormalized(...), $value)).']';
        }

        $members = [];
        foreach ($value as $key => $item) {
            $members[] = json_encode((string) $key, JSON_THROW_ON_ERROR).':'.self::encodeNormalized($item);
        }

        return '{'.implode(',', $members).'}';
    }

    private static function floatToken(float $value): string
    {
        if (! is_finite($value)) {
            // Preserve json_encode's rejection and exception type for NaN and infinities.
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }

        // Start at one significant digit and retain the first representation that parses back to
        // the original IEEE-754 value. This matches the shortest-round-trip representation PHP's
        // json_encode uses at serialize_precision=-1 without reading or changing that INI value.
        $token = '';
        for ($significantDigits = 1; $significantDigits <= 17; $significantDigits++) {
            $candidate = sprintf('%.'.$significantDigits.'g', $value);

            if ((float) $candidate === $value) {
                $token = strtolower($candidate);

                break;
            }
        }

        $token = preg_replace('/e([+-])0+(\d+)/', 'e$1$2', $token) ?? $token;
        $token = self::expandLegacyJsonExponent($token);

        if (! str_contains($token, '.')) {
            $exponent = strpos($token, 'e');
            if ($exponent === false) {
                return $token.'.0';
            }

            return substr($token, 0, $exponent).'.0'.substr($token, $exponent);
        }

        return $token;
    }

    private static function expandLegacyJsonExponent(string $token): string
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?e([+-]?\d+)$/', $token, $parts)) {
            return $token;
        }

        $exponent = (int) $parts[4];
        if ($exponent < -4 || $exponent > 16) {
            return $token;
        }

        $digits = $parts[2].$parts[3];
        $decimalPosition = strlen($parts[2]) + $exponent;

        if ($decimalPosition <= 0) {
            return $parts[1].'0.'.str_repeat('0', -$decimalPosition).$digits;
        }

        if ($decimalPosition >= strlen($digits)) {
            return $parts[1].$digits.str_repeat('0', $decimalPosition - strlen($digits));
        }

        return $parts[1].substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition);
    }
}
