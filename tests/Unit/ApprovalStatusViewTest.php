<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;

it('collapses a missing receipt to null and a present one to its view', function (): void {
    $now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
    $receipt = new ApprovalReceipt(
        id: 'receipt-view-unit',
        toolCallId: 'call-view-unit',
        capability: 'orders.cancel',
        bindingFingerprint: hash('sha256', 'receipt-view-unit'),
        provenance: null,
        approvalContext: ['tenant_id' => 7],
        status: ApprovalReceiptStatus::Pending,
        reason: null,
        expiresAt: $now->modify('+15 minutes'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $now,
        updatedAt: $now,
    );

    expect(ApprovalStatusView::fromNullableReceipt(null))->toBeNull()
        ->and(ApprovalStatusView::fromNullableReceipt($receipt)?->receiptId)->toBe('receipt-view-unit')
        ->and(ApprovalStatusView::fromNullableReceipt($receipt)?->approvalContext)->toBe(['tenant_id' => 7]);
});
