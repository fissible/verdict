<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Closure;

final class ApprovalExecutionContext
{
    /** @var list<ApprovedToolCalls> */
    private array $frames = [];

    public function allows(string $toolCallId): bool
    {
        $frame = end($this->frames);

        return $frame instanceof ApprovedToolCalls && $frame->allows($toolCallId);
    }

    public function within(ApprovedToolCalls $toolCallIds, Closure $callback): mixed
    {
        $this->push($toolCallIds);

        try {
            return $callback();
        } finally {
            $this->pop();
        }
    }

    public function push(ApprovedToolCalls $toolCallIds): void
    {
        $this->frames[] = $toolCallIds;
    }

    public function pop(): void
    {
        array_pop($this->frames);
    }
}
