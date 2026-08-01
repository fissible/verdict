<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalTransition;

interface ApprovalReceiptStore
{
    public function issue(ApprovalReceipt $receipt): ApprovalTransition;

    public function findForToolCall(string $toolCallId): ?ApprovalReceipt;

    public function approve(
        string $receiptId,
        string $toolCallId,
        string $approvedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition;

    public function reject(
        string $receiptId,
        string $toolCallId,
        string $rejectedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition;

    public function validate(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition;

    public function consume(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition;
}
