<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Evidence\CanonicalJson;

/**
 * A scheme-tagged SHA-256 digest of a declared, canonical-JSON resource projection.
 *
 * @experimental Part of the evaluation surface; may change before Verdict 1.0.
 */
final class ResourceDigest
{
    public const string SCHEME = 'resource-v1-canonicaljson-sha256';

    /** @param array<string, mixed> $projection */
    public static function for(array $projection): string
    {
        return self::SCHEME.':'.hash('sha256', CanonicalJson::encode($projection, 'resource-projection'));
    }

    public static function isDigest(string $value): bool
    {
        return preg_match('/^'.preg_quote(self::SCHEME, '/').':[a-f0-9]{64}\z/', $value) === 1;
    }
}
