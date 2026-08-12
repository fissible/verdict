<?php

declare(strict_types=1);

namespace App\Jobs;

use Fissible\Verdict\Approvals\ApprovalOutcome;

final class NotifyVerdictApprovalDecision
{
    public function __construct(
        public readonly string $receiptId,
        public readonly ApprovalOutcome $outcome,
    ) {}

    public function handle(): void
    {
        // TODO: Load application-owned tenant/conversation context, authorize recipients,
        // and call the application's notification transport. This class is not dispatched
        // or registered by Verdict.
    }
}
