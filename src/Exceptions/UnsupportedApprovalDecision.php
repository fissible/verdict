<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use LogicException;

final class UnsupportedApprovalDecision extends LogicException
{
    /**
     * Verdict receipts bind the original proposal, so edited arguments cannot be resumed safely.
     */
    public static function editedArguments(string $toolCallId): self
    {
        return new self("Verdict does not support edited-arguments approvals for tool call [{$toolCallId}]; resume with Decision::approve() for the original proposal.");
    }
}
