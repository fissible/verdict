<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

final class ContentFingerprint
{
    public static function make(mixed $content): string
    {
        return hash('sha256', CanonicalJson::encode($content, 'Provenance content'));
    }
}
