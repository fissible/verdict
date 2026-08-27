<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use InvalidArgumentException;

/**
 * The specific tool-call ids approved for an execution frame.
 *
 * This kernel value object keeps upstream approval vocabulary at the adapter boundary.
 */
final readonly class ApprovedToolCalls
{
    /** @param list<string> $toolCallIds */
    private function __construct(private array $toolCallIds) {}

    /** @param list<mixed> $toolCallIds */
    public static function of(array $toolCallIds): self
    {
        foreach ($toolCallIds as $toolCallId) {
            // '*' is upstream's "approve everything" marker, not a tool-call id. Admitting it would
            // authorize a call nobody specifically approved.
            if (! is_string($toolCallId) || $toolCallId === '*' || trim($toolCallId) === '') {
                throw new InvalidArgumentException('An approved tool call must be a non-blank, non-wildcard string id.');
            }
        }

        return new self($toolCallIds);
    }

    public function allows(string $toolCallId): bool
    {
        return in_array($toolCallId, $this->toolCallIds, true);
    }
}
