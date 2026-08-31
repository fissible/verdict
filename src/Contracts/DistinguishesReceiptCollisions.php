<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Approvals\ApprovalReceiptLookup;

/**
 * An opt-in capability beside ApprovalReceiptStore (#425): a store that can tell "no receipt for
 * this tool call" from "more than one".
 *
 * A tool-call id is a provider-supplied identifier, not a Verdict key — receipts are unique on
 * (tool_call_id, capability, binding_fingerprint) — so two receipts sharing one is legal and real:
 * a cross-capability collision, or a proposal that changed while its receipt was still open.
 * ApprovalReceiptStore::findForToolCall() cannot express that; its null means both. It is Stable
 * through 1.0, so the richer read is added here rather than folded into it, and a store that has
 * not adopted this interface keeps its existing behaviour unchanged.
 *
 * Nothing in Verdict requires this interface. ApprovalManager reads the ambiguous method and is
 * correct either way — a collision yields no challenge under both — so this exists for consumers
 * that must *see* the collision, chiefly a reviewer queue.
 */
interface DistinguishesReceiptCollisions
{
    /**
     * Every receipt for this tool call, as one of three outcomes: absent, single, or multiple.
     * Multiple carries no receipt — canonicalizing one would conceal the event a queue exists to
     * resolve — and every outcome carries its complete receipt-id list, ordered by createdAt then
     * id, with string ordering as the backing store collates it (the same order pendingWithin()
     * documents).
     */
    public function lookupForToolCall(string $toolCallId): ApprovalReceiptLookup;
}
