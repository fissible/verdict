<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Console\DatabaseTableStore;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Support\SecurityStateTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use stdClass;
use Throwable;

final readonly class DatabaseReviewRequestStore implements DatabaseTableStore, ReviewRequestStore
{
    /** Mutable memo inside a readonly class for lazily inspected optional columns. */
    private stdClass $schemaMemo;

    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_review_requests',
    ) {
        $this->schemaMemo = new stdClass;
    }

    public function hasApprovalContextColumn(): bool
    {
        return $this->hasColumn('approval_context');
    }

    public function hasApproverSummaryColumn(): bool
    {
        return $this->hasColumn('approver_summary');
    }

    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function hasTable(): bool
    {
        if (! $this->connection instanceof Connection) {
            throw new LogicException('The review request connection does not support schema inspection.');
        }

        return $this->connection->getSchemaBuilder()->hasTable($this->table);
    }

    public function table(): string
    {
        return $this->table;
    }

    public function issue(ReviewRequest $request): ReviewTransition
    {
        try {
            return SecurityStateTransaction::run($this->connection, 'issue a review request', function () use ($request): ReviewTransition {
                $existing = $this->lockedRequestForBinding($request->capability, $request->bindingFingerprint);

                if ($existing !== null) {
                    return $this->existingIssue($existing, $request);
                }

                $idCollision = $this->findLocked($request->id);

                if ($idCollision !== null) {
                    return ReviewTransition::to(ReviewOutcome::InvalidState, $idCollision);
                }

                $this->connection->table($this->table)->insert($this->attributes($request));

                return ReviewTransition::to(ReviewOutcome::Issued, $request);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = $this->requestForBinding($request->capability, $request->bindingFingerprint);

            if ($existing !== null) {
                return $this->existingIssue($existing, $request);
            }

            $idCollision = $this->find($request->id);

            return $idCollision === null
                ? ReviewTransition::to(ReviewOutcome::NotFound)
                : ReviewTransition::to(ReviewOutcome::InvalidState, $idCollision);
        }
    }

    public function find(string $requestId): ?ReviewRequest
    {
        $row = $this->connection->table($this->table)->where('id', $requestId)->first();

        return $row instanceof stdClass ? $this->requestFromRow($row) : null;
    }

    public function approve(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
    {
        return $this->resolve($requestId, $resolvedBy, $at, ReviewStatus::Approved, ReviewOutcome::Approved, 'approve');
    }

    public function reject(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
    {
        return $this->resolve($requestId, $resolvedBy, $at, ReviewStatus::Rejected, ReviewOutcome::Rejected, 'reject');
    }

    public function validate(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
    {
        return $this->validateRequest($this->requestForBinding($capability, $bindingFingerprint), $at);
    }

    public function consume(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
    {
        return SecurityStateTransaction::run($this->connection, 'consume a review request', function () use ($capability, $bindingFingerprint, $at): ReviewTransition {
            $request = $this->lockedRequestForBinding($capability, $bindingFingerprint);
            $validation = $this->validateRequest($request, $at);

            if (! $validation->succeeded()) {
                return $validation;
            }

            /** @var ReviewRequest $request */
            $this->connection->table($this->table)->where('id', $request->id)->update([
                'status' => ReviewStatus::Consumed->value,
                'consumed_at' => $at,
                'updated_at' => $at,
            ]);

            return ReviewTransition::to(ReviewOutcome::Consumed, $this->findLocked($request->id));
        });
    }

    private function resolve(
        string $requestId,
        string $resolvedBy,
        DateTimeImmutable $at,
        ReviewStatus $status,
        ReviewOutcome $outcome,
        string $operation,
    ): ReviewTransition {
        return SecurityStateTransaction::run($this->connection, "{$operation} a review request", function () use ($requestId, $resolvedBy, $at, $status, $outcome): ReviewTransition {
            $request = $this->findLocked($requestId);
            $failure = $this->transitionFailure($request, $at);

            if ($failure !== null) {
                return $failure;
            }

            /** @var ReviewRequest $request */
            $this->connection->table($this->table)->where('id', $request->id)->update([
                'status' => $status->value,
                'resolved_by' => $resolvedBy,
                'resolved_at' => $at,
                'updated_at' => $at,
            ]);

            return ReviewTransition::to($outcome, $this->findLocked($request->id));
        });
    }

    private function existingIssue(ReviewRequest $existing, ReviewRequest $proposed): ReviewTransition
    {
        if ($existing->isExpiredAt($proposed->createdAt)) {
            return ReviewTransition::to(ReviewOutcome::Expired, $existing);
        }

        if (! in_array($existing->status, [ReviewStatus::Pending, ReviewStatus::Approved], true)) {
            return ReviewTransition::to(ReviewOutcome::InvalidState, $existing);
        }

        return ReviewTransition::to(ReviewOutcome::Existing, $existing);
    }

    private function transitionFailure(?ReviewRequest $request, DateTimeImmutable $at): ?ReviewTransition
    {
        if ($request === null) {
            return ReviewTransition::to(ReviewOutcome::NotFound);
        }

        if ($request->isExpiredAt($at)) {
            return ReviewTransition::to(ReviewOutcome::Expired, $request);
        }

        if ($request->status !== ReviewStatus::Pending) {
            return ReviewTransition::to(ReviewOutcome::InvalidState, $request);
        }

        return null;
    }

    private function validateRequest(?ReviewRequest $request, DateTimeImmutable $at): ReviewTransition
    {
        if ($request === null) {
            return ReviewTransition::to(ReviewOutcome::NotFound);
        }

        if ($request->isExpiredAt($at)) {
            return ReviewTransition::to(ReviewOutcome::Expired, $request);
        }

        if ($request->status !== ReviewStatus::Approved) {
            return ReviewTransition::to(ReviewOutcome::InvalidState, $request);
        }

        return ReviewTransition::to(ReviewOutcome::Approved, $request);
    }

    private function lockedRequestForBinding(string $capability, string $bindingFingerprint): ?ReviewRequest
    {
        $row = $this->connection->table($this->table)
            ->where('capability', $capability)
            ->where('binding_fingerprint', $bindingFingerprint)
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->requestFromRow($row) : null;
    }

    private function requestForBinding(string $capability, string $bindingFingerprint): ?ReviewRequest
    {
        $row = $this->connection->table($this->table)
            ->where('capability', $capability)
            ->where('binding_fingerprint', $bindingFingerprint)
            ->first();

        return $row instanceof stdClass ? $this->requestFromRow($row) : null;
    }

    private function findLocked(string $requestId): ?ReviewRequest
    {
        $row = $this->connection->table($this->table)->where('id', $requestId)->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->requestFromRow($row) : null;
    }

    /** @return array<string, mixed> */
    private function attributes(ReviewRequest $request): array
    {
        $attributes = [
            'id' => $request->id,
            'capability' => $request->capability,
            'binding_fingerprint' => $request->bindingFingerprint,
            'status' => $request->status->value,
            'reason' => $request->reason,
            'expires_at' => $request->expiresAt,
            'resolved_by' => $request->resolvedBy,
            'resolved_at' => $request->resolvedAt,
            'consumed_at' => $request->consumedAt,
            'provenance' => $request->provenance === null ? null : json_encode($request->provenance->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => $request->createdAt,
            'updated_at' => $request->createdAt,
        ];

        if ($this->hasApprovalContextColumn()) {
            $attributes['approval_context'] = $request->approvalContext === null ? null : json_encode($request->approvalContext, JSON_THROW_ON_ERROR);
        }

        if ($this->hasApproverSummaryColumn()) {
            $attributes['approver_summary'] = $request->approverSummary === null ? null : json_encode($request->approverSummary->toArray(), JSON_THROW_ON_ERROR);
        }

        return $attributes;
    }

    private function requestFromRow(stdClass $row): ReviewRequest
    {
        return new ReviewRequest(
            id: (string) $row->id,
            capability: (string) $row->capability,
            bindingFingerprint: (string) $row->binding_fingerprint,
            approvalContext: $this->approvalContextFromRow($row),
            provenance: $this->provenanceFromRow($row),
            approverSummary: $this->approverSummaryFromRow($row),
            status: ReviewStatus::from((string) $row->status),
            reason: $row->reason === null ? null : (string) $row->reason,
            createdAt: $this->dateFromDatabase($row->created_at),
            expiresAt: $this->dateFromDatabase($row->expires_at),
            resolvedBy: $row->resolved_by === null ? null : (string) $row->resolved_by,
            resolvedAt: $row->resolved_at === null ? null : $this->dateFromDatabase($row->resolved_at),
            consumedAt: $row->consumed_at === null ? null : $this->dateFromDatabase($row->consumed_at),
        );
    }

    /** @return ?array<string, string|int> */
    private function approvalContextFromRow(stdClass $row): ?array
    {
        $decoded = $this->jsonArray($row->approval_context ?? null);

        return $decoded;
    }

    private function provenanceFromRow(stdClass $row): ?ProposalProvenance
    {
        $decoded = $this->jsonArray($row->provenance ?? null);

        if ($decoded === null) {
            return null;
        }

        try {
            return ProposalProvenance::fromArray($decoded);
        } catch (Throwable) {
            return null;
        }
    }

    private function approverSummaryFromRow(stdClass $row): ?ApproverSummary
    {
        $decoded = $this->jsonArray($row->approver_summary ?? null);

        if ($decoded === null) {
            return null;
        }

        try {
            return ApproverSummary::fromArray($decoded);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return ?array<string, mixed> */
    private function jsonArray(mixed $stored): ?array
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function dateFromDatabase(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    private function hasColumn(string $column): bool
    {
        if (! $this->connection instanceof Connection) {
            throw new LogicException('The review request connection does not support schema inspection.');
        }

        $property = 'has'.str_replace(' ', '', ucwords(str_replace('_', ' ', $column)));

        return $this->schemaMemo->{$property} ??= $this->connection->getSchemaBuilder()->hasColumn($this->table, $column);
    }
}
