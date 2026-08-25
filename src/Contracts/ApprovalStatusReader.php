<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Approvals\ApprovalStatusView;

/**
 * The approval read contract (ADR 0031): a narrow, observational, container-resolved read seam
 * over approval-receipt state, separate from the write/transition ApprovalReceiptStore, which is
 * not widened for reads.
 *
 * Freshness is poll-consistency, stated: a read reflects every transition committed before the
 * read began; nothing pushes. Reads carry no authority — approve(), reject(), and consume() each
 * re-validate status and expiry inside their locked transaction, so a stale read can never cause
 * a wrong transition; it can only render a row as actionable one poll interval longer than it
 * was.
 */
interface ApprovalStatusReader
{
    /**
     * The status view of the receipt with this id, or null when the store has none. Reads back
     * decided receipts too — this is the read that un-collapses challengeForToolCall()'s null
     * into "already decided" versus "lapsed, undecided".
     */
    public function statusFor(string $receiptId): ?ApprovalStatusView;

    /**
     * The status view of the single receipt for this tool call. Inherits
     * ApprovalReceiptStore::findForToolCall()'s documented ambiguity: null when there is none OR
     * when more than one receipt shares the tool call id, so null never proves absence.
     * Consumers that hold a receiptId should prefer statusFor().
     */
    public function statusForToolCall(string $toolCallId): ?ApprovalStatusView;

    /**
     * The receipts whose persisted lifecycle status is Pending and whose approval_context
     * contains every scope pair with the same typed canonical value (ADR 0031 §3). An empty
     * scope throws InvalidArgumentException. Receipts with null or empty context never
     * enumerate. A lapsed-but-undecided receipt is still returned with its expiresAt — expiry is
     * the consumer's clock comparison, never a reported status. Deterministic order: createdAt
     * ascending, then receiptId.
     *
     * @param  non-empty-array<string, string|int>  $scope
     * @return list<ApprovalStatusView>
     */
    public function pendingWithin(array $scope): array;
}
