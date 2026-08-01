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
        $approved = [];

        foreach ($decisions->all() as $toolCallId => $decision) {
            if ($toolCallId !== '*' && $decision->isApproved()) {
                $approved[$toolCallId] = true;
            }
        }

        $this->frames[] = $approved;

        try {
            return $callback();
        } finally {
            array_pop($this->frames);
        }
    }
}
