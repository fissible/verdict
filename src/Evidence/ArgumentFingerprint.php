<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

final class ArgumentFingerprint
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function make(array $arguments): string
    {
        return hash('sha256', json_encode(
            self::normalize($arguments),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }
}
