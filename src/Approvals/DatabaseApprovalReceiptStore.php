<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Approvals\Events\ApprovalProposalChangedUnderOpenReceipt;
use Fissible\Verdict\Approvals\Events\ApprovalReceiptTransitioned;
use Fissible\Verdict\Console\DatabaseTableStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\DistinguishesReceiptCollisions;
use Fissible\Verdict\Contracts\EnforcesDecisionAdmissibility;
use Fissible\Verdict\Support\ApproverSummary;
use Fissible\Verdict\Support\SecurityStateTransaction;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use stdClass;

final readonly class DatabaseApprovalReceiptStore implements ApprovalReceiptStore, DatabaseTableStore, DistinguishesReceiptCollisions, EnforcesDecisionAdmissibility
{
    /**
     * Mutable memo inside a readonly class: holds the lazily-checked presence of the
     * approval_context column so the schema is inspected at most once per store instance,
     * and only on the first write that would touch the column.
     */
    private stdClass $schemaMemo;

    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_approval_receipts',
        private ?Dispatcher $events = null,
    ) {
        $this->schemaMemo = new stdClass;
    }

    /**
     * Whether the approval_context column exists. False on an install that composer-updated
     * without running the published add_approval_context migration: writes then omit the column
     * (receipts hydrate as never-captured, and a fail-closed authorizer refuses them) instead of
     * hard-failing every confirmation-gated issue(). verdict:validate reports the missing column.
     */
    public function hasApprovalContextColumn(): bool
    {
        if (! $this->connection instanceof Connection) {
            throw new LogicException('The approval receipt connection does not support schema inspection.');
        }

        return $this->schemaMemo->hasApprovalContext ??= $this->connection
            ->getSchemaBuilder()
            ->hasColumn($this->table, 'approval_context');
    }

    /**
     * Whether the approver-summary columns exist. An install that has not yet run the additive
     * migration continues issuing receipts, but cannot durably retain this feature's fields.
     */
    public function hasApproverSummaryColumn(): bool
    {
        if (! $this->connection instanceof Connection) {
            throw new LogicException('The approval receipt connection does not support schema inspection.');
        }

        return $this->schemaMemo->hasApproverSummary ??= $this->connection
            ->getSchemaBuilder()
            ->hasColumn($this->table, 'approver_summary');
    }

    private function hasApproverSummaryReleaseColumn(): bool
    {
        if (! $this->connection instanceof Connection) {
            throw new LogicException('The approval receipt connection does not support schema inspection.');
        }

        return $this->schemaMemo->hasApproverSummaryRelease ??= $this->connection
            ->getSchemaBuilder()
            ->hasColumn($this->table, 'approver_summary_release');
    }

    /**
     * The connection this store reads and writes, for the paired DatabaseApprovalStatusReader
     * (ADR 0031 §2) — deriving it here instead of re-resolving configuration guarantees the
     * reader enumerates the same database the store transitions, however the store was bound.
     *
     * @internal
     */
    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function hasTable(): bool
    {
        if (! $this->connection instanceof Connection) {
            throw new LogicException('The approval receipt connection does not support schema inspection.');
        }

        return $this->connection->getSchemaBuilder()->hasTable($this->table);
    }

    public function table(): string
    {
        return $this->table;
    }

    public function issue(ApprovalReceipt $receipt): ApprovalTransition
    {
        $openReceipt = null;
        $transitionedReceipt = null;

        try {
            $transition = SecurityStateTransaction::run($this->connection, 'issue an approval receipt', function () use ($receipt, &$transitionedReceipt, &$openReceipt): ApprovalTransition {
                // A retried closure must discard a rolled-back attempt's receipt; otherwise it could be announced after the successful attempt.
                $transitionedReceipt = null;
                $existing = $this->lockedReceiptForBinding(
                    $receipt->toolCallId,
                    $receipt->capability,
                    $receipt->bindingFingerprint,
                );

                if ($existing !== null) {
                    return $this->existingIssue($existing, $receipt);
                }

                $openReceipt = $this->lockedOpenReceiptForChangedProposal($receipt);
                $this->connection->table($this->table)->insert($this->attributes($receipt));
                $transitionedReceipt = $receipt;

                return ApprovalTransition::to(ApprovalOutcome::Issued, $receipt);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = $this->receiptForBinding(
                $receipt->toolCallId,
                $receipt->capability,
                $receipt->bindingFingerprint,
            );

            return $existing === null
                ? ApprovalTransition::to(ApprovalOutcome::NotFound)
                : $this->existingIssue($existing, $receipt);
        }

        if ($transitionedReceipt !== null) {
            $this->events?->dispatch(ApprovalReceiptTransitioned::from(
                $transitionedReceipt,
                ApprovalReceiptStatus::Pending,
                $transitionedReceipt->createdAt,
            ));
        }

        // Once the transaction returns, a captured receipt means the write occurred, which is equivalent here to an Issued outcome.
        if ($transitionedReceipt !== null && $openReceipt !== null) {
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

    public function findForToolCall(string $toolCallId): ?ApprovalReceipt
    {
        $rows = $this->connection->table($this->table)
            ->where('tool_call_id', $toolCallId)
            ->limit(2)
            ->get();

        return $rows->count() === 1 && $rows->first() instanceof stdClass
            ? $this->receiptFromRow($rows->first())
            : null;
    }

    /**
     * The #425 collision seam. One ordered read, so the common single result is fully hydrated
     * without a second query; collisions are rare and must expose every id rather than the
     * limit(2) sample findForToolCall() is content with.
     */
    public function lookupForToolCall(string $toolCallId): ApprovalReceiptLookup
    {
        $receipts = $this->connection->table($this->table)
            ->where('tool_call_id', $toolCallId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): ApprovalReceipt => $this->receiptFromRow($row))
            ->values()
            ->all();

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
        $row = $this->connection->table($this->table)
            ->where('id', $receiptId)
            ->first();

        return $row instanceof stdClass ? $this->receiptFromRow($row) : null;
    }

    public function approve(
        string $receiptId,
        string $toolCallId,
        string $approvedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        $transitionedReceipt = null;

        $transition = SecurityStateTransaction::run($this->connection, 'approve an approval receipt', function () use ($receiptId, $toolCallId, $approvedBy, $at, &$transitionedReceipt): ApprovalTransition {
            $transitionedReceipt = null;
            $receipt = $this->findLocked($receiptId);
            $failure = $this->decisionFailure($receipt, $toolCallId, $at);

            if ($failure !== null) {
                return $failure;
            }

            /** @var ApprovalReceipt $receipt */
            $this->connection->table($this->table)
                ->where('id', $receipt->id)
                ->update([
                    'status' => ApprovalReceiptStatus::Approved->value,
                    'approved_by' => $approvedBy,
                    'approved_at' => $at,
                    'updated_at' => $at,
                ]);
            $transitionedReceipt = $receipt;

            return ApprovalTransition::to(ApprovalOutcome::Approved, $this->findLocked($receipt->id));
        });

        if ($transitionedReceipt !== null) {
            $this->events?->dispatch(ApprovalReceiptTransitioned::from(
                $transitionedReceipt,
                ApprovalReceiptStatus::Approved,
                $at,
            ));
        }

        return $transition;
    }

    public function reject(
        string $receiptId,
        string $toolCallId,
        string $rejectedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        $transitionedReceipt = null;

        $transition = SecurityStateTransaction::run($this->connection, 'reject an approval receipt', function () use ($receiptId, $toolCallId, $rejectedBy, $at, &$transitionedReceipt): ApprovalTransition {
            $transitionedReceipt = null;
            $receipt = $this->findLocked($receiptId);
            $failure = $this->decisionFailure($receipt, $toolCallId, $at);

            if ($failure !== null) {
                return $failure;
            }

            /** @var ApprovalReceipt $receipt */
            $this->connection->table($this->table)
                ->where('id', $receipt->id)
                ->update([
                    'status' => ApprovalReceiptStatus::Rejected->value,
                    'rejected_by' => $rejectedBy,
                    'rejected_at' => $at,
                    'updated_at' => $at,
                ]);
            $transitionedReceipt = $receipt;

            return ApprovalTransition::to(ApprovalOutcome::Rejected, $this->findLocked($receipt->id));
        });

        if ($transitionedReceipt !== null) {
            $this->events?->dispatch(ApprovalReceiptTransitioned::from(
                $transitionedReceipt,
                ApprovalReceiptStatus::Rejected,
                $at,
            ));
        }

        return $transition;
    }

    public function consume(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        $transitionedReceipt = null;

        $transition = SecurityStateTransaction::run($this->connection, 'consume an approval receipt', function () use ($toolCallId, $bindingFingerprint, $at, &$transitionedReceipt): ApprovalTransition {
            $transitionedReceipt = null;
            $receipt = $this->lockedReceiptForBindingFingerprint($toolCallId, $bindingFingerprint);
            $validation = $this->validateReceipt($receipt, $bindingFingerprint, $at);

            if (! $validation->succeeded()) {
                return $validation;
            }

            /** @var ApprovalReceipt $receipt */
            $this->connection->table($this->table)
                ->where('id', $receipt->id)
                ->update([
                    'status' => ApprovalReceiptStatus::Consumed->value,
                    'consumed_at' => $at,
                    'updated_at' => $at,
                ]);
            $transitionedReceipt = $receipt;

            return ApprovalTransition::to(ApprovalOutcome::Consumed, $this->findLocked($receipt->id));
        });

        if ($transitionedReceipt !== null) {
            $this->events?->dispatch(ApprovalReceiptTransitioned::from(
                $transitionedReceipt,
                ApprovalReceiptStatus::Consumed,
                $at,
            ));
        }

        return $transition;
    }

    public function validate(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        return $this->validateReceipt(
            $this->receiptForBindingFingerprint($toolCallId, $bindingFingerprint),
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

    private function existingIssue(ApprovalReceipt $existing, ApprovalReceipt $proposed): ApprovalTransition
    {
        if ($existing->capability !== $proposed->capability
            || ! hash_equals($existing->bindingFingerprint, $proposed->bindingFingerprint)) {
            return ApprovalTransition::to(ApprovalOutcome::Mismatch, $existing);
        }

        if ($existing->isExpiredAt($proposed->createdAt)) {
            return ApprovalTransition::to(ApprovalOutcome::Expired, $existing);
        }

        if (! in_array($existing->status, [ApprovalReceiptStatus::Pending, ApprovalReceiptStatus::Approved], true)) {
            return ApprovalTransition::to(ApprovalOutcome::InvalidState, $existing);
        }

        return ApprovalTransition::to(ApprovalOutcome::Existing, $existing);
    }

    private function decisionFailure(
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

    private function lockedReceiptForBinding(
        string $toolCallId,
        string $capability,
        string $bindingFingerprint,
    ): ?ApprovalReceipt {
        $row = $this->connection->table($this->table)
            ->where('tool_call_id', $toolCallId)
            ->where('capability', $capability)
            ->where('binding_fingerprint', $bindingFingerprint)
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->receiptFromRow($row) : null;
    }

    private function lockedOpenReceiptForChangedProposal(ApprovalReceipt $receipt): ?ApprovalReceipt
    {
        $row = $this->connection->table($this->table)
            ->where('tool_call_id', $receipt->toolCallId)
            ->where('capability', $receipt->capability)
            ->where('binding_fingerprint', '!=', $receipt->bindingFingerprint)
            ->whereIn('status', [ApprovalReceiptStatus::Pending->value, ApprovalReceiptStatus::Approved->value])
            ->where('expires_at', '>', $receipt->createdAt)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->receiptFromRow($row) : null;
    }

    private function lockedReceiptForBindingFingerprint(
        string $toolCallId,
        string $bindingFingerprint,
    ): ?ApprovalReceipt {
        $row = $this->connection->table($this->table)
            ->where('tool_call_id', $toolCallId)
            ->where('binding_fingerprint', $bindingFingerprint)
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->receiptFromRow($row) : null;
    }

    private function receiptForBindingFingerprint(
        string $toolCallId,
        string $bindingFingerprint,
    ): ?ApprovalReceipt {
        $row = $this->connection->table($this->table)
            ->where('tool_call_id', $toolCallId)
            ->where('binding_fingerprint', $bindingFingerprint)
            ->first();

        return $row instanceof stdClass ? $this->receiptFromRow($row) : null;
    }

    private function receiptForBinding(
        string $toolCallId,
        string $capability,
        string $bindingFingerprint,
    ): ?ApprovalReceipt {
        $row = $this->connection->table($this->table)
            ->where('tool_call_id', $toolCallId)
            ->where('capability', $capability)
            ->where('binding_fingerprint', $bindingFingerprint)
            ->first();

        return $row instanceof stdClass ? $this->receiptFromRow($row) : null;
    }

    private function findLocked(string $receiptId): ?ApprovalReceipt
    {
        $row = $this->connection->table($this->table)
            ->where('id', $receiptId)
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->receiptFromRow($row) : null;
    }

    /** @return array<string, mixed> */
    private function attributes(ApprovalReceipt $receipt): array
    {
        $attributes = [
            'id' => $receipt->id,
            'tool_call_id' => $receipt->toolCallId,
            'capability' => $receipt->capability,
            'binding_fingerprint' => $receipt->bindingFingerprint,
            'provenance' => $receipt->provenance === null
                ? null
                : json_encode($receipt->provenance->toArray(), JSON_THROW_ON_ERROR),
            'status' => $receipt->status->value,
            'reason' => $receipt->reason,
            'expires_at' => $receipt->expiresAt,
            'approved_by' => $receipt->approvedBy,
            'approved_at' => $receipt->approvedAt,
            'rejected_by' => $receipt->rejectedBy,
            'rejected_at' => $receipt->rejectedAt,
            'consumed_at' => $receipt->consumedAt,
            'created_at' => $receipt->createdAt,
            'updated_at' => $receipt->updatedAt,
        ];

        if ($this->hasApprovalContextColumn()) {
            $attributes['approval_context'] = $receipt->approvalContext === null
                ? null
                : json_encode($receipt->approvalContext, JSON_THROW_ON_ERROR);
        }

        if ($this->hasApproverSummaryColumn() && $this->hasApproverSummaryReleaseColumn()) {
            $attributes['approver_summary'] = $receipt->approverSummary === null
                ? null
                : json_encode($receipt->approverSummary->toArray(), JSON_THROW_ON_ERROR);
            $attributes['approver_summary_release'] = $receipt->approverSummaryRelease?->value;
        }

        return $attributes;
    }

    private function receiptFromRow(stdClass $row): ApprovalReceipt
    {
        return new ApprovalReceipt(
            id: (string) $row->id,
            toolCallId: (string) $row->tool_call_id,
            capability: (string) $row->capability,
            bindingFingerprint: (string) $row->binding_fingerprint,
            provenance: $this->provenanceFromRow($row),
            approvalContext: $this->approvalContextFromRow($row),
            status: ApprovalReceiptStatus::from((string) $row->status),
            reason: $row->reason === null ? null : (string) $row->reason,
            expiresAt: $this->dateFromDatabase($row->expires_at),
            approvedBy: $row->approved_by === null ? null : (string) $row->approved_by,
            approvedAt: $row->approved_at === null ? null : $this->dateFromDatabase($row->approved_at),
            rejectedBy: $row->rejected_by === null ? null : (string) $row->rejected_by,
            rejectedAt: $row->rejected_at === null ? null : $this->dateFromDatabase($row->rejected_at),
            consumedAt: $row->consumed_at === null ? null : $this->dateFromDatabase($row->consumed_at),
            createdAt: $this->dateFromDatabase($row->created_at),
            updatedAt: $this->dateFromDatabase($row->updated_at),
            approverSummary: $this->approverSummaryFromRow($row),
            approverSummaryRelease: $this->approverSummaryReleaseFromRow($row),
        );
    }

    /**
     * Null when the row predates the approval_context column ("never captured") or the
     * column itself is absent because the add-column migration has not run yet.
     *
     * @return ?array<string, string|int>
     */
    private function approvalContextFromRow(stdClass $row): ?array
    {
        $stored = $row->approval_context ?? null;

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, string|int> $decoded */
        return $decoded;
    }

    private function provenanceFromRow(stdClass $row): ?ProposalProvenance
    {
        $stored = $row->provenance ?? null;

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? ProposalProvenance::fromArray($decoded) : null;
    }

    private function approverSummaryFromRow(stdClass $row): ?ApproverSummary
    {
        $stored = $row->approver_summary ?? null;

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? ApproverSummary::fromArray($decoded) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function approverSummaryReleaseFromRow(stdClass $row): ?ApproverSummaryRelease
    {
        return is_string($row->approver_summary_release ?? null)
            ? ApproverSummaryRelease::tryFrom($row->approver_summary_release)
            : null;
    }

    private function dateFromDatabase(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
