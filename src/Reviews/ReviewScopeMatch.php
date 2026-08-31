<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use InvalidArgumentException;

/**
 * The containment semantics of ADR 0035 §4, shared by every ReviewStatusReader implementation:
 * a request matches a scope iff every requested key exists on the request's approval_context with
 * the same typed canonical value. No coercion — an integer 1 does not match the string '1'. A
 * null or empty context never matches.
 *
 * @internal
 */
final class ReviewScopeMatch
{
    /**
     * @param  array<string, string|int>  $scope
     */
    public static function assertScope(array $scope): void
    {
        if ($scope === []) {
            throw new InvalidArgumentException(
                'pendingWithin() requires a non-empty approval-context scope; an unscoped pending list is deliberately not expressible (ADR 0035).'
            );
        }
    }

    /**
     * @param  ?array<string, string|int>  $context
     * @param  array<string, string|int>  $scope
     */
    public static function matches(?array $context, array $scope): bool
    {
        if ($context === null || $context === []) {
            return false;
        }

        foreach ($scope as $key => $value) {
            if (! array_key_exists($key, $context) || $context[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
