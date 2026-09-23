<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Contracts\DistinguishesStatusCollisions;

/**
 * The reader paired with DatabaseApprovalReceiptStore (ADR 0031 §2), on the store's own
 * connection. Status reads ride the store's lookups. Enumeration discovers candidates with a
 * portable query — persisted status Pending and a non-empty stored approval_context —
 * applies the typed containment of ADR 0031 §3 in PHP on the decoded
 * context, and hydrates only the matches through the store's bulk findMany() in one read per
 * 1,000 matches. The store remains the single row-mapping authority; no backend JSON containment
 * operator, or any backend's number/string coercion, is involved (#327's portability decision).
 *
 * The contractual order is imposed in PHP after hydration: second-precision createdAt, then
 * byte-order id. SQL ORDER BY id would inherit the connection's collation, so mixed-case ids
 * would enumerate differently under MySQL's default case-insensitive collation.
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
            ->get(['id', 'approval_context']);

        $matchedIds = [];

        foreach ($candidates as $row) {
            if (! is_string($row->id) || ! is_string($row->approval_context)) {
                continue;
            }

            $context = json_decode($row->approval_context, true);

            if (! is_array($context) || ! ApprovalScopeMatch::matches($context, $scope)) {
                continue;
            }

            $matchedIds[] = $row->id;
        }

        $receipts = $this->store->findMany($matchedIds);
        $matches = [];

        foreach ($matchedIds as $receiptId) {
            $receipt = $receipts[$receiptId] ?? null;

            // Re-checked after hydration: a transition committed between the candidate query and
            // the hydration is poll-consistency at work, not an error — the resolved receipt simply
            // no longer enumerates.
            if ($receipt === null || $receipt->status !== ApprovalReceiptStatus::Pending) {
                continue;
            }

            $matches[] = $receipt;
        }

        // createdAt at the precision the write path persists, then the receipt id, compared as text
        // so neither a collation nor PHP's numeric-string comparison can reorder them.
        usort($matches, static fn (ApprovalReceipt $a, ApprovalReceipt $b): int => strcmp(
            $a->createdAt->format('Y-m-d H:i:s'),
            $b->createdAt->format('Y-m-d H:i:s'),
        ) ?: strcmp($a->id, $b->id));

        return array_map(
            static fn (ApprovalReceipt $receipt): ApprovalStatusView => ApprovalStatusView::fromReceipt($receipt),
            $matches,
        );
    }
}
