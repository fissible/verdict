<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Closure;
use DateTimeImmutable;
use Fissible\Verdict\Approvals\Events\ApprovalProposalChangedUnderOpenReceipt;
use Fissible\Verdict\Approvals\Events\ApprovalReceiptTransitioned;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Fissible\Verdict\Contracts\DistinguishesReceiptCollisions;
use Fissible\Verdict\Contracts\EnforcesDecisionAdmissibility;
use Fissible\Verdict\Contracts\IssuesAdmittedReceipts;
use Fissible\Verdict\Contracts\PrunableApprovalReceiptStore;
use Fissible\Verdict\Contracts\PrunesConsumedApprovalPayload;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardCollision;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardSchemeDowngraded;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardSchemeMismatch;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;

/**
 * Process-local test store. It is not safe for production, Octane, or queue workers.
 */
final class InMemoryApprovalReceiptStore implements ApprovalReceiptStore, DistinguishesReceiptCollisions, EnforcesDecisionAdmissibility, IssuesAdmittedReceipts, PrunableApprovalReceiptStore, PrunesConsumedApprovalPayload
{
    /** @var array<string, ApprovalReceipt> */
    private array $receipts = [];

    /**
     * Memoised presence of a schemed guard row, queried at most once per store instance so the
     * downgrade check is a per-instance cost, not a per-request one.
     */
    private ?bool $schemedGuardPresent = null;

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
    private function activeGuard(string $toolCallId, string $capability, string $bindingFingerprint): DerivedGuard
    {
        return $this->scheme?->active($toolCallId, $capability, $bindingFingerprint)
            ?? new DerivedGuard(ConsumedBindingGuard::digest($toolCallId, $capability, $bindingFingerprint), null, null);
    }

    /**
     * After-match validation (ADR 0039): whether a matched guard's stored scheme self-consistently
     * describes its digest. A configured scheme delegates to describes(); a keyless deployment (no
     * scheme) can only verify a keyless row, re-deriving the unkeyed digest itself. A false result
     * fails the probe closed with ConsumedBindingGuardSchemeMismatch.
     */
    private function guardIsConsistent(DerivedGuard $found, string $tc, string $cap, string $bf): bool
    {
        if ($this->scheme !== null) {
            return $this->scheme->describes($found, $tc, $cap, $bf);
        }

        return $found->algorithm === null
            && $found->keyVersion === null
            && $found->digest === ConsumedBindingGuard::digest($tc, $cap, $bf);
    }

    /**
     * Fail closed on a keyed->keyless guard downgrade (ADR 0039, #514 follow-up): under a
     * keyless-effective scheme (no scheme, or a scheme with no keys) a surviving keyed guard row can
     * no longer be re-derived, so guardCandidates() would never probe it and a consumed-and-pruned
     * binding could silently re-issue. Refuse rather than reopen the replay window. Keyed-effective
     * schemes still probe their keyed candidates, so they are exempt. The guard store is asked at most
     * once per instance.
     */
    private function assertNotDowngraded(): void
    {
        if ($this->guards === null) {
            return;
        }

        if ($this->scheme !== null && $this->scheme->hasKeys()) {
            return; // keyed-effective: keyed candidates are still probed
        }

        // keyless-effective (no scheme, or a scheme with no keys): a surviving keyed guard is orphaned.
        if ($this->schemedGuardPresent ??= $this->guards->hasSchemedGuard()) {
            throw new ConsumedBindingGuardSchemeDowngraded(
                'A consumed-binding keyed guard exists but the resolved scheme has no keys; refusing to operate rather than silently reopen the replay window. Restore the retained keys (verdict.approvals.consumed_binding_guard.keys) to recover.'
            );
        }
    }

    public function issue(ApprovalReceipt $receipt): ApprovalTransition
    {
        return $this->issueAdmitted($receipt, static fn () => null);
    }

    public function issueAdmitted(ApprovalReceipt $receipt, Closure $onAdmitted): ApprovalTransition
    {
        $existing = $this->findForBinding(
            $receipt->toolCallId,
            $receipt->capability,
            $receipt->bindingFingerprint,
        );

        if ($existing === null) {
            if ($this->guards !== null) {
                foreach ($this->guardCandidates($receipt->toolCallId, $receipt->capability, $receipt->bindingFingerprint) as $candidate) {
                    $found = $this->guards->lookup($candidate);

                    if ($found !== null) {
                        if (! $this->guardIsConsistent($found, $receipt->toolCallId, $receipt->capability, $receipt->bindingFingerprint)) {
                            throw new ConsumedBindingGuardSchemeMismatch(
                                "A consumed-binding guard's stored scheme does not describe its digest."
                            );
                        }

                        return ApprovalTransition::to(ApprovalOutcome::PreviouslyConsumed);
                    }
                }

                $this->assertNotDowngraded();
            }

            $openReceipt = $this->mostRecentOpenReceiptForChangedProposal($receipt);

            // Clear to mint: the binding admits a new receipt. Run the hook before persisting so a
            // throw leaves nothing minted and propagates unchanged.
            $onAdmitted();

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
            static fn (ApprovalReceipt $a, ApprovalReceipt $b): int => strcmp(
                $a->createdAt->format('Y-m-d H:i:s'),
                $b->createdAt->format('Y-m-d H:i:s'),
            ) ?: strcmp($a->id, $b->id),
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
        $this->assertNotDowngraded();

        if ($this->guards === null) {
            throw new RuntimeException('Pruning consumed approval payloads requires a consumed-binding guard store.');
        }

        $count = 0;

        foreach ($this->receipts as $id => $receipt) {
            if ($receipt->status === ApprovalReceiptStatus::Consumed
                && $receipt->consumedAt !== null
                && $receipt->consumedAt <= $consumedBefore) {
                $guard = $this->activeGuard($receipt->toolCallId, $receipt->capability, $receipt->bindingFingerprint);
                $this->guards->remember($guard->digest, $receipt->consumedAt, $guard->algorithm, $guard->keyVersion);

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
                $found = $this->guards->lookup($candidate);

                if ($found !== null) {
                    if (! $this->guardIsConsistent($found, $receipt->toolCallId, $receipt->capability, $bindingFingerprint)) {
                        throw new ConsumedBindingGuardSchemeMismatch(
                            "A consumed-binding guard's stored scheme does not describe its digest."
                        );
                    }

                    throw new ConsumedBindingGuardCollision('The approval binding has already been consumed.');
                }
            }

            $this->assertNotDowngraded();

            $guard = $this->activeGuard($receipt->toolCallId, $receipt->capability, $bindingFingerprint);
            $this->guards->remember($guard->digest, $at, $guard->algorithm, $guard->keyVersion);
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
