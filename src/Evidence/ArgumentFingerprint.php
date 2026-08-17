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
        return hash('sha256', self::canonicalJson($arguments));
    }

    public static function canonicalJson(mixed $value): string
    {
        return CanonicalJson::encode($value, 'A fingerprinted binding');
    }
}
