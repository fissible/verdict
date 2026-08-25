<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Contracts\ApprovalStatusReader;

/**
 * The reader paired with InMemoryApprovalReceiptStore (ADR 0031 §2): status reads ride the
 * store's own lookups; enumeration reads the same process-local state the store holds, filtered
 * by the shared containment semantics. Test-scoped like its store — not for production.
 */
final readonly class InMemoryApprovalStatusReader implements ApprovalStatusReader
{
    public function __construct(
        private InMemoryApprovalReceiptStore $store,
    ) {}

    public function statusFor(string $receiptId): ?ApprovalStatusView
    {
        $receipt = $this->store->find($receiptId);

        return $receipt === null ? null : ApprovalStatusView::fromReceipt($receipt);
    }

    public function statusForToolCall(string $toolCallId): ?ApprovalStatusView
    {
        $receipt = $this->store->findForToolCall($toolCallId);

        return $receipt === null ? null : ApprovalStatusView::fromReceipt($receipt);
    }

    public function pendingWithin(array $scope): array
    {
        ApprovalScopeMatch::assertScope($scope);

        $matching = array_filter(
            $this->store->allReceipts(),
            static fn (ApprovalReceipt $receipt): bool => $receipt->status === ApprovalReceiptStatus::Pending
                && ApprovalScopeMatch::matches($receipt->approvalContext, $scope),
        );

        usort(
            $matching,
            static fn (ApprovalReceipt $a, ApprovalReceipt $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id],
        );

        return array_map(
            static fn (ApprovalReceipt $receipt): ApprovalStatusView => ApprovalStatusView::fromReceipt($receipt),
            $matching,
        );
    }
}
