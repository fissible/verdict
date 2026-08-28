<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Evidence\CanonicalJson;

/**
 * An opaque, scheme-tagged fingerprint of a capability-declared execution-target identity.
 *
 * The input is deliberately just the array returned by `ExecutionTargetPolicy::identity()`. This
 * layer neither knows nor reconstructs an application's resource fields: the policy is the one
 * declaration of what makes two refreshed targets the same logical resource.
 *
 * @experimental Part of the evaluation surface; may change before Verdict 1.0.
 */
final class ResourceIdentity
{
    public const string SCHEME = 'resourceidentity-v1-canonicaljson-sha256';

    /** @param array<string, mixed> $identity */
    public static function for(array $identity): string
    {
        return self::SCHEME.':'.hash('sha256', CanonicalJson::encode($identity, 'resource identity'));
    }

    public static function isIdentity(string $value): bool
    {
        return preg_match('/^'.preg_quote(self::SCHEME, '/').':[a-f0-9]{64}\z/', $value) === 1;
    }
}
