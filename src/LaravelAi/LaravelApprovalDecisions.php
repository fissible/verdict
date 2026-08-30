<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Fissible\Verdict\Approvals\ApprovedToolCalls;
use Fissible\Verdict\Exceptions\UnsupportedApprovalDecision;
use Laravel\Ai\Approvals\Decisions;

/**
 * Translates Laravel AI approval vocabulary once at the adapter boundary.
 *
 * Per ADR 0033 §2, the kernel receives only approved tool-call ids and never sees Decisions.
 */
final class LaravelApprovalDecisions
{
    public static function approvedToolCalls(Decisions $decisions): ApprovedToolCalls
    {
        $approvedToolCalls = [];

        foreach ($decisions->all() as $toolCallId => $decision) {
            // A Verdict receipt binds the proposal's original arguments, so an edited proposal
            // cannot be translated into a safe approval for the same tool-call id.
            if ($decision->isEdited()) {
                throw UnsupportedApprovalDecision::editedArguments($toolCallId);
            }

            if ($toolCallId !== '*' && $decision->isApproved()) {
                $approvedToolCalls[] = $toolCallId;
            }
        }

        return ApprovedToolCalls::of($approvedToolCalls);
    }
}
