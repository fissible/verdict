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
        return ApprovalStatusView::fromNullableReceipt($this->store->find($receiptId));
    }

    public function statusForToolCall(string $toolCallId): ApprovalStatusLookup
    {
        return ApprovalStatusLookup::fromReceiptLookup($this->store->findForToolCall($toolCallId));
    }

    public function pendingWithin(array $scope): array
    {
        ApprovalScopeMatch::assertScope($scope);

        $matching = array_values(array_filter(
            $this->store->all(),
            static fn (ApprovalReceipt $receipt): bool => $receipt->status === ApprovalReceiptStatus::Pending
                && ApprovalScopeMatch::matches($receipt->approvalContext, $scope),
        ));

        // Second-precision createdAt, matching what the database reader inherits from the
        // column's stored 'Y-m-d H:i:s' — the two shipped readers order identically.
        usort(
            $matching,
            static fn (ApprovalReceipt $a, ApprovalReceipt $b): int => [$a->createdAt->format('Y-m-d H:i:s'), $a->id]
                <=> [$b->createdAt->format('Y-m-d H:i:s'), $b->id],
        );

        return array_map(
            static fn (ApprovalReceipt $receipt): ApprovalStatusView => ApprovalStatusView::fromReceipt($receipt),
            $matching,
        );
    }
}
