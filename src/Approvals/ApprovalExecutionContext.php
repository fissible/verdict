<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Closure;
use Laravel\Ai\Approvals\Decisions;

final class ApprovalExecutionContext
{
    /** @var list<array<string, true>> */
    private array $frames = [];

    public function allows(string $toolCallId): bool
    {
        $frame = end($this->frames);

        return is_array($frame) && isset($frame[$toolCallId]);
    }

    public function within(Decisions $decisions, Closure $callback): mixed
    {
        $this->push($decisions);

        try {
            return $callback();
        } finally {
            $this->pop();
        }
    }

    public function push(Decisions $decisions): void
    {
        $approved = [];

        foreach ($decisions->all() as $toolCallId => $decision) {
            if ($toolCallId !== '*' && $decision->isApproved()) {
                $approved[$toolCallId] = true;
            }
        }

        $this->frames[] = $approved;
    }

    public function pop(): void
    {
        array_pop($this->frames);
    }
}
