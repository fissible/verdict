<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use InvalidArgumentException;

/**
 * The containment semantics of ADR 0031 §3, shared by every ApprovalStatusReader implementation
 * so no backend can drift: a receipt matches a scope iff every requested key exists on the
 * receipt's approval_context with the same typed canonical value. No coercion — an integer 1
 * does not match the string '1' — and no nested-structure matching: both sides are canonical
 * scalar maps. A null or empty context never matches; applications that do not capture approval
 * context keep the application-owned join path (#106).
 *
 * @internal
 */
final class ApprovalScopeMatch
{
    /**
     * An empty scope throws: the unscoped global pending list #106 rejected stays rejected, and
     * this is that rejection made mechanical (ADR 0031 §3).
     *
     * @param  array<string, string|int>  $scope
     */
    public static function assertScope(array $scope): void
    {
        if ($scope === []) {
            throw new InvalidArgumentException(
                'pendingWithin() requires a non-empty approval-context scope; an unscoped pending list is deliberately not expressible (ADR 0031).'
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
