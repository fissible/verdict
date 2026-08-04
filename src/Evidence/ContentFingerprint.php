<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use InvalidArgumentException;

final class ContentFingerprint
{
    public static function make(mixed $content): string
    {
        return hash('sha256', json_encode(
            self::normalize($content),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_null($value) || is_scalar($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Provenance content must be a scalar, null, or an array of those values.');
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }
}
