<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Contracts\DistinguishesStatusCollisions;

/**
 * The reader paired with DatabaseApprovalReceiptStore (ADR 0031 §2), on the store's own
 * connection. Status reads ride the store's lookups. Enumeration discovers candidates with a
 * portable query — persisted status Pending, a non-empty stored approval_context, ordered by
 * created_at then id — applies the typed containment of ADR 0031 §3 in PHP on the decoded
 * context, and hydrates only the matches through the store's find(), so the store stays the
 * single row-mapping authority and the per-row reads are bounded by the scoped match set. No
 * backend JSON containment operator, and none of any backend's number/string coercion, is
 * involved (#327's portability decision).
 *
 * On an install that has not run the add_approval_context migration, no receipt has a context,
 * so enumeration honestly returns nothing; verdict:validate reports the missing column. The
 * column's presence is memoized per store instance, so a long-lived worker that ran before the
 * migration must be restarted after it — the standard worker-restart obligation for any
 * deploy-time schema or configuration change.
 */
final readonly class DatabaseApprovalStatusReader implements ApprovalStatusReader, DistinguishesStatusCollisions
{
    public function __construct(
        private DatabaseApprovalReceiptStore $store,
    ) {}

    public function statusFor(string $receiptId): ?ApprovalStatusView
    {
        return ApprovalStatusView::fromNullableReceipt($this->store->find($receiptId));
    }

    public function statusForToolCall(string $toolCallId): ?ApprovalStatusView
    {
        return ApprovalStatusView::fromNullableReceipt($this->store->findForToolCall($toolCallId));
    }

    public function statusLookupForToolCall(string $toolCallId): ApprovalStatusLookup
    {
        return ApprovalStatusLookup::fromReceiptLookup($this->store->lookupForToolCall($toolCallId));
    }

    public function pendingWithin(array $scope): array
    {
        ApprovalScopeMatch::assertScope($scope);

        if (! $this->store->hasApprovalContextColumn()) {
            return [];
        }

        // '[]' is a real stored value — a context captured empty — and can never match a
        // non-empty scope, so it is excluded alongside NULL before any row leaves the database.
        $candidates = $this->store->connection()->table($this->store->table())
            ->where('status', ApprovalReceiptStatus::Pending->value)
            ->whereNotNull('approval_context')
            ->where('approval_context', '!=', '[]')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'approval_context']);

        $views = [];

        foreach ($candidates as $row) {
            if (! is_string($row->id) || ! is_string($row->approval_context)) {
                continue;
            }

            $context = json_decode($row->approval_context, true);

            if (! is_array($context) || ! ApprovalScopeMatch::matches($context, $scope)) {
                continue;
            }

            $receipt = $this->store->find($row->id);

            // Re-checked after hydration: a transition committed between the candidate query and
            // the find() is poll-consistency at work, not an error — the resolved receipt simply
            // no longer enumerates.
            if ($receipt === null || $receipt->status !== ApprovalReceiptStatus::Pending) {
                continue;
            }

            $views[] = ApprovalStatusView::fromReceipt($receipt);
        }

        return $views;
    }
}
