<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use stdClass;

final readonly class DatabaseApprovalReceiptStore implements ApprovalReceiptStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_approval_receipts',
    ) {}

    public function issue(ApprovalReceipt $receipt): ApprovalTransition
    {
        try {
            return $this->connection->transaction(function () use ($receipt): ApprovalTransition {
                $existing = $this->lockedReceiptForBinding(
                    $receipt->toolCallId,
                    $receipt->capability,
                    $receipt->bindingFingerprint,
                );

                if ($existing !== null) {
                    return $this->existingIssue($existing, $receipt);
                }

                $this->connection->table($this->table)->insert($this->attributes($receipt));

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

    public function approve(
        string $receiptId,
        string $toolCallId,
        string $approvedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        return $this->connection->transaction(function () use ($receiptId, $toolCallId, $approvedBy, $at): ApprovalTransition {
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

            return ApprovalTransition::to(ApprovalOutcome::Approved, $this->findLocked($receipt->id));
        });
    }

    public function reject(
        string $receiptId,
        string $toolCallId,
        string $rejectedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        return $this->connection->transaction(function () use ($receiptId, $toolCallId, $rejectedBy, $at): ApprovalTransition {
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

            return ApprovalTransition::to(ApprovalOutcome::Rejected, $this->findLocked($receipt->id));
        });
    }

    public function consume(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        return $this->connection->transaction(function () use ($toolCallId, $bindingFingerprint, $at): ApprovalTransition {
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

            return ApprovalTransition::to(ApprovalOutcome::Consumed, $this->findLocked($receipt->id));
        });
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
        return [
            'id' => $receipt->id,
            'tool_call_id' => $receipt->toolCallId,
            'capability' => $receipt->capability,
            'binding_fingerprint' => $receipt->bindingFingerprint,
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
    }

    private function receiptFromRow(stdClass $row): ApprovalReceipt
    {
        return new ApprovalReceipt(
            id: (string) $row->id,
            toolCallId: (string) $row->tool_call_id,
            capability: (string) $row->capability,
            bindingFingerprint: (string) $row->binding_fingerprint,
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
        );
    }

    private function dateFromDatabase(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
