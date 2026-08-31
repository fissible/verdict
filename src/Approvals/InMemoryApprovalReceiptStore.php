<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\Events\ApprovalProposalChangedUnderOpenReceipt;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\DistinguishesReceiptCollisions;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Process-local test store. It is not safe for production, Octane, or queue workers.
 */
final class InMemoryApprovalReceiptStore implements ApprovalReceiptStore, DistinguishesReceiptCollisions
{
    /** @var array<string, ApprovalReceipt> */
    private array $receipts = [];

    public function __construct(private readonly ?Dispatcher $events = null) {}

    public function issue(ApprovalReceipt $receipt): ApprovalTransition
    {
        $existing = $this->findForBinding(
            $receipt->toolCallId,
            $receipt->capability,
            $receipt->bindingFingerprint,
        );

        if ($existing === null) {
            $openReceipt = $this->mostRecentOpenReceiptForChangedProposal($receipt);
            $this->receipts[$receipt->id] = $receipt;

            $transition = ApprovalTransition::to(ApprovalOutcome::Issued, $receipt);

            if ($openReceipt !== null) {
                $this->events?->dispatch(new ApprovalProposalChangedUnderOpenReceipt(
                    toolCallId: $receipt->toolCallId,
                    capability: $receipt->capability,
                    openReceiptId: $openReceipt->id,
                    openReceiptFingerprint: $openReceipt->bindingFingerprint,
                    newReceiptId: $receipt->id,
                    newReceiptFingerprint: $receipt->bindingFingerprint,
                ));
            }

            return $transition;
        }

        if ($existing->isExpiredAt($receipt->createdAt)) {
            return ApprovalTransition::to(ApprovalOutcome::Expired, $existing);
        }

        if (! in_array($existing->status, [ApprovalReceiptStatus::Pending, ApprovalReceiptStatus::Approved], true)) {
            return ApprovalTransition::to(ApprovalOutcome::InvalidState, $existing);
        }

        return ApprovalTransition::to(ApprovalOutcome::Existing, $existing);
    }

    public function findForToolCall(string $toolCallId): ?ApprovalReceipt
    {
        $matches = array_values(array_filter(
            $this->receipts,
            static fn (ApprovalReceipt $receipt): bool => $receipt->toolCallId === $toolCallId,
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** The #425 collision seam, ordered exactly as the database store orders it. */
    public function lookupForToolCall(string $toolCallId): ApprovalReceiptLookup
    {
        $receipts = array_values(array_filter(
            $this->receipts,
            static fn (ApprovalReceipt $receipt): bool => $receipt->toolCallId === $toolCallId,
        ));

        // Second-precision createdAt, matching what the database store inherits from the column's
        // stored 'Y-m-d H:i:s' — the two shipped stores order identically.
        usort(
            $receipts,
            static fn (ApprovalReceipt $a, ApprovalReceipt $b): int => [$a->createdAt->format('Y-m-d H:i:s'), $a->id]
                <=> [$b->createdAt->format('Y-m-d H:i:s'), $b->id],
        );

        return match (count($receipts)) {
            0 => ApprovalReceiptLookup::absent(),
            1 => ApprovalReceiptLookup::single($receipts[0]),
            default => ApprovalReceiptLookup::multiple(array_map(
                static fn (ApprovalReceipt $receipt): string => $receipt->id,
                $receipts,
            )),
        };
    }

    public function find(string $receiptId): ?ApprovalReceipt
    {
        return $this->receipts[$receiptId] ?? null;
    }

    public function approve(
        string $receiptId,
        string $toolCallId,
        string $approvedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        $receipt = $this->receipts[$receiptId] ?? null;
        $failure = $this->transitionFailure($receipt, $toolCallId, $at);

        if ($failure !== null) {
            return $failure;
        }

        /** @var ApprovalReceipt $receipt */
        $updated = $this->replace(
            $receipt,
            status: ApprovalReceiptStatus::Approved,
            approvedBy: $approvedBy,
            approvedAt: $at,
            updatedAt: $at,
        );

        return ApprovalTransition::to(ApprovalOutcome::Approved, $updated);
    }

    public function reject(
        string $receiptId,
        string $toolCallId,
        string $rejectedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        $receipt = $this->receipts[$receiptId] ?? null;
        $failure = $this->transitionFailure($receipt, $toolCallId, $at);

        if ($failure !== null) {
            return $failure;
        }

        /** @var ApprovalReceipt $receipt */
        $updated = $this->replace(
            $receipt,
            status: ApprovalReceiptStatus::Rejected,
            rejectedBy: $rejectedBy,
            rejectedAt: $at,
            updatedAt: $at,
        );

        return ApprovalTransition::to(ApprovalOutcome::Rejected, $updated);
    }

    public function consume(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        $receipt = $this->findForBindingFingerprint($toolCallId, $bindingFingerprint);

        $validation = $this->validateReceipt($receipt, $bindingFingerprint, $at);

        if (! $validation->succeeded()) {
            return $validation;
        }

        /** @var ApprovalReceipt $receipt */
        $updated = $this->replace(
            $receipt,
            status: ApprovalReceiptStatus::Consumed,
            consumedAt: $at,
            updatedAt: $at,
        );

        return ApprovalTransition::to(ApprovalOutcome::Consumed, $updated);
    }

    public function validate(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        return $this->validateReceipt(
            $this->findForBindingFingerprint($toolCallId, $bindingFingerprint),
            $bindingFingerprint,
            $at,
        );
    }

    private function validateReceipt(
        ?ApprovalReceipt $receipt,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition {

        if ($receipt === null) {
            return ApprovalTransition::to(ApprovalOutcome::NotFound);
        }

        if (! hash_equals($receipt->bindingFingerprint, $bindingFingerprint)) {
            return ApprovalTransition::to(ApprovalOutcome::Mismatch, $receipt);
        }

        if ($receipt->isExpiredAt($at)) {
            return ApprovalTransition::to(ApprovalOutcome::Expired, $receipt);
        }

        if ($receipt->status !== ApprovalReceiptStatus::Approved) {
            return ApprovalTransition::to(ApprovalOutcome::InvalidState, $receipt);
        }

        return ApprovalTransition::to(ApprovalOutcome::Approved, $receipt);
    }

    /** @return array<string, ApprovalReceipt> */
    public function all(): array
    {
        return $this->receipts;
    }

    private function transitionFailure(
        ?ApprovalReceipt $receipt,
        string $toolCallId,
        DateTimeImmutable $at,
    ): ?ApprovalTransition {
        if ($receipt === null) {
            return ApprovalTransition::to(ApprovalOutcome::NotFound);
        }

        if (! hash_equals($receipt->toolCallId, $toolCallId)) {
            return ApprovalTransition::to(ApprovalOutcome::Mismatch, $receipt);
        }

        if ($receipt->isExpiredAt($at)) {
            return ApprovalTransition::to(ApprovalOutcome::Expired, $receipt);
        }

        if ($receipt->status !== ApprovalReceiptStatus::Pending) {
            return ApprovalTransition::to(ApprovalOutcome::InvalidState, $receipt);
        }

        return null;
    }

    private function replace(
        ApprovalReceipt $receipt,
        ?ApprovalReceiptStatus $status = null,
        ?string $approvedBy = null,
        ?DateTimeImmutable $approvedAt = null,
        ?string $rejectedBy = null,
        ?DateTimeImmutable $rejectedAt = null,
        ?DateTimeImmutable $consumedAt = null,
        ?DateTimeImmutable $updatedAt = null,
    ): ApprovalReceipt {
        $updated = new ApprovalReceipt(
            id: $receipt->id,
            toolCallId: $receipt->toolCallId,
            capability: $receipt->capability,
            bindingFingerprint: $receipt->bindingFingerprint,
            provenance: $receipt->provenance,
            approvalContext: $receipt->approvalContext,
            status: $status ?? $receipt->status,
            reason: $receipt->reason,
            expiresAt: $receipt->expiresAt,
            approvedBy: $approvedBy ?? $receipt->approvedBy,
            approvedAt: $approvedAt ?? $receipt->approvedAt,
            rejectedBy: $rejectedBy ?? $receipt->rejectedBy,
            rejectedAt: $rejectedAt ?? $receipt->rejectedAt,
            consumedAt: $consumedAt ?? $receipt->consumedAt,
            createdAt: $receipt->createdAt,
            updatedAt: $updatedAt ?? $receipt->updatedAt,
        );

        $this->receipts[$receipt->id] = $updated;

        return $updated;
    }

    private function findForBinding(
        string $toolCallId,
        string $capability,
        string $bindingFingerprint,
    ): ?ApprovalReceipt {
        foreach ($this->receipts as $receipt) {
            if ($receipt->toolCallId === $toolCallId
                && $receipt->capability === $capability
                && hash_equals($receipt->bindingFingerprint, $bindingFingerprint)) {
                return $receipt;
            }
        }

        return null;
    }

    private function mostRecentOpenReceiptForChangedProposal(ApprovalReceipt $proposal): ?ApprovalReceipt
    {
        $selected = null;

        foreach ($this->receipts as $receipt) {
            if ($receipt->toolCallId !== $proposal->toolCallId
                || $receipt->capability !== $proposal->capability
                || hash_equals($receipt->bindingFingerprint, $proposal->bindingFingerprint)
                || ! in_array($receipt->status, [ApprovalReceiptStatus::Pending, ApprovalReceiptStatus::Approved], true)
                || $receipt->isExpiredAt($proposal->createdAt)) {
                continue;
            }

            if ($selected === null
                || $receipt->createdAt > $selected->createdAt
                || ($receipt->createdAt == $selected->createdAt && $receipt->id > $selected->id)) {
                $selected = $receipt;
            }
        }

        return $selected;
    }

    private function findForBindingFingerprint(string $toolCallId, string $bindingFingerprint): ?ApprovalReceipt
    {
        foreach ($this->receipts as $receipt) {
            if ($receipt->toolCallId === $toolCallId
                && hash_equals($receipt->bindingFingerprint, $bindingFingerprint)) {
                return $receipt;
            }
        }

        return null;
    }
}
