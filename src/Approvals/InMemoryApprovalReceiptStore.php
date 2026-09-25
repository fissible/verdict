<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\Events\ApprovalProposalChangedUnderOpenReceipt;
use Fissible\Verdict\Approvals\Events\ApprovalReceiptTransitioned;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Fissible\Verdict\Contracts\DistinguishesReceiptCollisions;
use Fissible\Verdict\Contracts\EnforcesDecisionAdmissibility;
use Fissible\Verdict\Contracts\PrunableApprovalReceiptStore;
use Fissible\Verdict\Contracts\PrunesConsumedApprovalPayload;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardCollision;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;

/**
 * Process-local test store. It is not safe for production, Octane, or queue workers.
 */
final class InMemoryApprovalReceiptStore implements ApprovalReceiptStore, DistinguishesReceiptCollisions, EnforcesDecisionAdmissibility, PrunableApprovalReceiptStore, PrunesConsumedApprovalPayload
{
    /** @var array<string, ApprovalReceipt> */
    private array $receipts = [];

    public function __construct(
        private readonly ?Dispatcher $events = null,
        private readonly ?ConsumedBindingGuardStore $guards = null,
        private readonly ?ConsumedBindingGuardScheme $scheme = null,
    ) {}

    /**
     * The digests issue() and consume() probe for a prior guard: the keyless digest plus one per
     * retained keyed version when a scheme is configured, else the single keyless digest.
     *
     * @return list<string>
     */
    private function guardCandidates(string $toolCallId, string $capability, string $bindingFingerprint): array
    {
        return $this->scheme?->candidates($toolCallId, $capability, $bindingFingerprint)
            ?? [ConsumedBindingGuard::digest($toolCallId, $capability, $bindingFingerprint)];
    }

    /**
     * The digest consume() records, under the active scheme (keyed when an active version is set,
     * else keyless). May throw MissingConsumedBindingGuardKey when the active key has no secret.
     */
    private function activeGuardDigest(string $toolCallId, string $capability, string $bindingFingerprint): string
    {
        return $this->scheme?->active($toolCallId, $capability, $bindingFingerprint)->digest
            ?? ConsumedBindingGuard::digest($toolCallId, $capability, $bindingFingerprint);
    }

    public function issue(ApprovalReceipt $receipt): ApprovalTransition
    {
        $existing = $this->findForBinding(
            $receipt->toolCallId,
            $receipt->capability,
            $receipt->bindingFingerprint,
        );

        if ($existing === null) {
            if ($this->guards !== null) {
                foreach ($this->guardCandidates($receipt->toolCallId, $receipt->capability, $receipt->bindingFingerprint) as $candidate) {
                    if ($this->guards->has($candidate)) {
                        return ApprovalTransition::to(ApprovalOutcome::PreviouslyConsumed);
                    }
                }
            }

            $openReceipt = $this->mostRecentOpenReceiptForChangedProposal($receipt);
            $this->receipts[$receipt->id] = $receipt;

            $transition = ApprovalTransition::to(ApprovalOutcome::Issued, $receipt);

            $this->events?->dispatch(ApprovalReceiptTransitioned::from(
                $receipt,
                ApprovalReceiptStatus::Pending,
                $receipt->createdAt,
            ));

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

    /**
     * Remove only expired receipts that never admitted an execution. A consumed receipt is the
     * single-use execution gate; deleting it would free its binding and could admit a second
     * human-approved execution. The expiry boundary is inclusive.
     */
    public function pruneExpired(DateTimeImmutable $before): int
    {
        $count = 0;

        foreach ($this->receipts as $id => $receipt) {
            if ($receipt->expiresAt <= $before && $receipt->status !== ApprovalReceiptStatus::Consumed) {
                unset($this->receipts[$id]);
                $count++;
            }
        }

        return $count;
    }

    public function pruneConsumedPayload(DateTimeImmutable $consumedBefore): int
    {
        if ($this->guards === null) {
            throw new RuntimeException('Pruning consumed approval payloads requires a consumed-binding guard store.');
        }

        $count = 0;

        foreach ($this->receipts as $id => $receipt) {
            if ($receipt->status === ApprovalReceiptStatus::Consumed
                && $receipt->consumedAt !== null
                && $receipt->consumedAt <= $consumedBefore) {
                $this->guards->remember(
                    ConsumedBindingGuard::digest($receipt->toolCallId, $receipt->capability, $receipt->bindingFingerprint),
                    $receipt->consumedAt,
                );

                unset($this->receipts[$id]);
                $count++;
            }
        }

        return $count;
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

        $this->events?->dispatch(ApprovalReceiptTransitioned::from(
            $updated,
            ApprovalReceiptStatus::Approved,
            $at,
        ));

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

        $this->events?->dispatch(ApprovalReceiptTransitioned::from(
            $updated,
            ApprovalReceiptStatus::Rejected,
            $at,
        ));

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
        if ($this->guards !== null) {
            foreach ($this->guardCandidates($receipt->toolCallId, $receipt->capability, $bindingFingerprint) as $candidate) {
                if ($this->guards->has($candidate)) {
                    throw new ConsumedBindingGuardCollision('The approval binding has already been consumed.');
                }
            }

            $this->guards->remember($this->activeGuardDigest($receipt->toolCallId, $receipt->capability, $bindingFingerprint), $at);
        }

        $updated = $this->replace(
            $receipt,
            status: ApprovalReceiptStatus::Consumed,
            consumedAt: $at,
            updatedAt: $at,
        );

        $this->events?->dispatch(ApprovalReceiptTransitioned::from(
            $updated,
            ApprovalReceiptStatus::Consumed,
            $at,
        ));

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
            approverSummary: $receipt->approverSummary,
            approverSummaryRelease: $receipt->approverSummaryRelease,
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
