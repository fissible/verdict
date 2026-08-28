<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;

/**
 * An in-memory receipt store that can lose its receipts, and reports what was read (#343).
 *
 * `CapabilitySecurityTestKit::assertApprovalBindingInvalidation()` demonstrates that an approval
 * cannot survive a change to the binding it was granted against, and its evidence is an
 * `approval_outcome = not_found` on the re-run. But the receipt lookup is keyed on the tool-call id
 * AND the recomputed binding fingerprint, so `not_found` is equally what a *vanished receipt*
 * produces. A scenario whose invalidation callback lost the receipt would pass while demonstrating
 * nothing.
 *
 * The contract has no delete — deliberately — so losing a receipt requires a store that can be told
 * to. Loss is modelled by replacing the backing store rather than by intercepting individual reads:
 * a receipt that is gone is gone to `validate()`, `consume()`, and `approve()` alike, and an earlier
 * version of this double that hid it from only the lookups produced a state no real loss reaches —
 * simultaneously missing and valid — which made a test fail for the wrong reason.
 *
 * `receiptReads()` exists so a test can assert the kit actually performed a continuity read, rather
 * than only that the scenario's outcome was unchanged. Without it a positive control cannot tell a
 * kit that checks continuity from one that does not.
 */
final class LosableApprovalReceiptStore implements ApprovalReceiptStore
{
    private InMemoryApprovalReceiptStore $inner;

    /** @var list<string> */
    private array $operations = [];

    public function __construct()
    {
        $this->inner = new InMemoryApprovalReceiptStore;
    }

    /** Everything the store held is gone, to every operation, permanently. */
    public function lose(): void
    {
        $this->inner = new InMemoryApprovalReceiptStore;
    }

    /**
     * Every store operation, in order, tagged with the id it addressed and — for a read by id — the
     * status it returned. A count of reads cannot distinguish a continuity check on the approved
     * receipt from an unrelated read, nor one performed before the re-run from one after it; an
     * ordered log can answer both.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function issue(ApprovalReceipt $receipt): ApprovalTransition
    {
        return $this->inner->issue($receipt);
    }

    public function findForToolCall(string $toolCallId): ?ApprovalReceipt
    {
        return $this->inner->findForToolCall($toolCallId);
    }

    public function find(string $receiptId): ?ApprovalReceipt
    {
        $receipt = $this->inner->find($receiptId);

        $this->operations[] = 'find:'.$receiptId.':'.($receipt?->status->value ?? 'missing');

        return $receipt;
    }

    public function approve(string $receiptId, string $toolCallId, string $approvedBy, DateTimeImmutable $at): ApprovalTransition
    {
        $this->operations[] = 'approve:'.$receiptId;

        return $this->inner->approve($receiptId, $toolCallId, $approvedBy, $at);
    }

    public function reject(string $receiptId, string $toolCallId, string $rejectedBy, DateTimeImmutable $at): ApprovalTransition
    {
        return $this->inner->reject($receiptId, $toolCallId, $rejectedBy, $at);
    }

    public function validate(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        $this->operations[] = 'validate:'.$toolCallId;

        return $this->inner->validate($toolCallId, $bindingFingerprint, $at);
    }

    public function consume(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        return $this->inner->consume($toolCallId, $bindingFingerprint, $at);
    }
}
