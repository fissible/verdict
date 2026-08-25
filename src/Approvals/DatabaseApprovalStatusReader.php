<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Illuminate\Database\ConnectionInterface;

/**
 * The reader paired with DatabaseApprovalReceiptStore (ADR 0031 §2). Status reads ride the
 * store's own lookups. Enumeration discovers candidate ids with a portable query — persisted
 * status Pending, approval_context present, ordered by created_at then id — and hydrates each
 * through the store's find(), so the store stays the single row-mapping authority. The typed
 * containment of ADR 0031 §3 is then applied in PHP on the decoded context: the same semantics
 * on SQLite, MySQL, and PostgreSQL, with no reliance on any backend's JSON containment operator
 * or its number/string coercion behavior (#327's portability decision).
 *
 * On an install that has not run the add_approval_context migration, no receipt has a context,
 * so enumeration honestly returns nothing; verdict:validate reports the missing column.
 */
final readonly class DatabaseApprovalStatusReader implements ApprovalStatusReader
{
    public function __construct(
        private DatabaseApprovalReceiptStore $store,
        private ConnectionInterface $connection,
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

        if (! $this->store->hasApprovalContextColumn()) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->connection->table($this->store->table())
            ->where('status', ApprovalReceiptStatus::Pending->value)
            ->whereNotNull('approval_context')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $views = [];

        foreach ($ids as $id) {
            $receipt = $this->store->find($id);

            // Re-checked after hydration: a transition committed between the candidate query and
            // the find() is poll-consistency at work, not an error — the resolved receipt simply
            // no longer enumerates.
            if ($receipt === null || $receipt->status !== ApprovalReceiptStatus::Pending) {
                continue;
            }

            if (! ApprovalScopeMatch::matches($receipt->approvalContext, $scope)) {
                continue;
            }

            $views[] = ApprovalStatusView::fromReceipt($receipt);
        }

        return $views;
    }
}
