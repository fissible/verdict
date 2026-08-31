<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptLookup;
use Fissible\Verdict\Approvals\ApprovalTransition;

/**
 * Operational security state for human approval, keyed two ways with different guarantees:
 * decisions (approve/reject) address a receipt by its id, execution-side checks
 * (validate/consume) address it by tool call + binding fingerprint. Custom implementations are a
 * supported extension point; the invariants below are what ApprovalManager depends on.
 */
interface ApprovalReceiptStore
{
    /**
     * Idempotent per (toolCallId, capability, bindingFingerprint): re-issuing the same binding
     * returns Existing rather than a second receipt.
     */
    public function issue(ApprovalReceipt $receipt): ApprovalTransition;

    /**
     * Looks up receipts by a provider-supplied tool-call id. The result explicitly distinguishes
     * absence, one receipt, and multiple receipts; the latter is a legal collision under the
     * three-part issue identity and must not be canonicalized. Every result carries all matching
     * receipt ids in created-at then id order. Use find() to address a receipt by its unique id.
     */
    public function findForToolCall(string $toolCallId): ApprovalReceiptLookup;

    /**
     * The receipt with this id, or null. Ids are unique, so this is never ambiguous;
     * ApprovalManager reads the receipt here before consulting the decision authorizer, so it
     * must see exactly the receipt approve()/reject() would transition.
     */
    public function find(string $receiptId): ?ApprovalReceipt;

    /**
     * Finalize by receipt id; the store owns the canonical failure outcomes
     * (NotFound/Mismatch/Expired/InvalidState) and the transition must be atomic. A decision is
     * admissible only when, at $at, the identified receipt has the matching tool call, is Pending,
     * and is unexpired; every other receipt must be refused with the applicable failure outcome.
     * ApprovalManager consults its decision authorizer only for a receipt this predicate admits, so
     * a store that finalizes a terminal or expired receipt finalizes it without authorization.
     */
    public function approve(
        string $receiptId,
        string $toolCallId,
        string $approvedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition;

    /** Finalize by receipt id, with the same admissibility and atomicity guarantees as approve(). */
    public function reject(
        string $receiptId,
        string $toolCallId,
        string $rejectedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition;

    /** Execution-side check by tool call + binding fingerprint; does not mutate the receipt. */
    public function validate(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition;

    /** Single-use execution admission by tool call + binding fingerprint; atomic. */
    public function consume(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition;
}
