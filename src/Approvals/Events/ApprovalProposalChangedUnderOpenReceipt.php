<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals\Events;

/**
 * A second approval receipt was minted for a tool-call/capability that already had an OPEN
 * receipt (pending or approved, not yet expired) under a DIFFERENT action binding — i.e. the
 * agent re-proposed the same tool call with changed arguments while the first proposal was still
 * awaiting a human decision.
 *
 * The mint is deliberate and fail-closed: the changed proposal binds to its own fingerprint and
 * needs its own approval, so nothing is auto-approved. But without a signal an adopter UI that keys
 * a human decision on `toolCallId` alone cannot tell which of the now-two open receipts the decision
 * refers to, and could pair an approval with the wrong proposal. This event is that signal; it
 * carries both receipt identities and both bindings so the UI can disambiguate.
 */
final readonly class ApprovalProposalChangedUnderOpenReceipt
{
    public function __construct(
        public string $toolCallId,
        public string $capability,
        public string $openReceiptId,
        public string $openReceiptFingerprint,
        public string $newReceiptId,
        public string $newReceiptFingerprint,
    ) {}
}
