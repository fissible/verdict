<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;

/**
 * Minimal custom receipt store implementing only the ApprovalReceiptStore contract, used to prove
 * the service provider pairs an unknown store with the store-backed status reader (issue #327).
 */
final class CustomStatusReaderTestStore implements ApprovalReceiptStore
{
    public function issue(ApprovalReceipt $receipt): ApprovalTransition
    {
        return ApprovalTransition::to(ApprovalOutcome::Issued, $receipt);
    }

    public function findForToolCall(string $toolCallId): ?ApprovalReceipt
    {
        return null;
    }

    public function find(string $receiptId): ?ApprovalReceipt
    {
        return null;
    }

    public function approve(string $receiptId, string $toolCallId, string $approvedBy, DateTimeImmutable $at): ApprovalTransition
    {
        return ApprovalTransition::to(ApprovalOutcome::NotFound);
    }

    public function reject(string $receiptId, string $toolCallId, string $rejectedBy, DateTimeImmutable $at): ApprovalTransition
    {
        return ApprovalTransition::to(ApprovalOutcome::NotFound);
    }

    public function validate(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        return ApprovalTransition::to(ApprovalOutcome::NotFound);
    }

    public function consume(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        return ApprovalTransition::to(ApprovalOutcome::NotFound);
    }
}
