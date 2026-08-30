<?php

declare(strict_types=1);

namespace Fissible\Verdict\ExecutionClaims;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Console\DatabaseTableStore;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Support\SecurityStateTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use stdClass;

final readonly class DatabaseExecutionClaimStore implements DatabaseTableStore, ExecutionClaimStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_execution_claims',
    ) {}

    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function hasTable(): bool
    {
        if (! $this->connection instanceof Connection) {
            throw new LogicException('The execution-claim connection does not support schema inspection.');
        }

        return $this->connection->getSchemaBuilder()->hasTable($this->table);
    }

    public function table(): string
    {
        return $this->table;
    }

    public function claim(ExecutionClaim $claim): ExecutionClaimTransition
    {
        try {
            return SecurityStateTransaction::run($this->connection, 'claim an execution', function () use ($claim): ExecutionClaimTransition {
                $existing = $this->findLockedByBinding($claim->bindingFingerprint);

                if ($existing !== null) {
                    return $this->claimExisting($existing, $claim->claimedAt);
                }

                $this->connection->table($this->table)->insert($this->attributes($claim));

                return ExecutionClaimTransition::to(ExecutionClaimOutcome::Claimed, $claim);
            });
        } catch (UniqueConstraintViolationException) {
            return SecurityStateTransaction::run($this->connection, 'claim an execution', function () use ($claim): ExecutionClaimTransition {
                $existing = $this->findLockedByBinding($claim->bindingFingerprint);

                return $existing === null
                    ? ExecutionClaimTransition::to(ExecutionClaimOutcome::NotFound)
                    : $this->claimExisting($existing, $claim->claimedAt);
            });
        }
    }

    public function complete(string $claimId, DateTimeImmutable $at): ExecutionClaimTransition
    {
        return $this->transitionClaimed($claimId, ExecutionClaimStatus::Completed, $at);
    }

    public function markIndeterminate(string $claimId, DateTimeImmutable $at): ExecutionClaimTransition
    {
        return $this->transitionClaimed($claimId, ExecutionClaimStatus::Indeterminate, $at);
    }

    public function resolve(
        string $claimId,
        ExecutionClaimResolution $resolution,
        string $resolvedBy,
        string $reason,
        DateTimeImmutable $at,
    ): ExecutionClaimTransition {
        return SecurityStateTransaction::run($this->connection, 'resolve an execution claim', function () use ($claimId, $resolution, $resolvedBy, $reason, $at): ExecutionClaimTransition {
            $claim = $this->findLocked($claimId);

            if ($claim === null) {
                return ExecutionClaimTransition::to(ExecutionClaimOutcome::NotFound);
            }

            if (! in_array($claim->status, [ExecutionClaimStatus::Claimed, ExecutionClaimStatus::Indeterminate], true)) {
                return ExecutionClaimTransition::to(ExecutionClaimOutcome::InvalidState, $claim);
            }

            $status = $resolution === ExecutionClaimResolution::Completed
                ? ExecutionClaimStatus::Completed
                : ExecutionClaimStatus::Released;
            $attributes = [
                'status' => $status->value,
                'resolved_by' => $resolvedBy,
                'resolution_reason' => $reason,
                'updated_at' => $at,
            ];

            if ($status === ExecutionClaimStatus::Completed) {
                $attributes['completed_at'] = $at;
            } else {
                $attributes['released_at'] = $at;
            }

            $this->connection->table($this->table)->where('id', $claimId)->update($attributes);

            return ExecutionClaimTransition::to(
                $status === ExecutionClaimStatus::Completed
                    ? ExecutionClaimOutcome::Completed
                    : ExecutionClaimOutcome::Released,
                $this->findLocked($claimId),
            );
        });
    }

    public function find(string $claimId): ?ExecutionClaim
    {
        $row = $this->connection->table($this->table)->where('id', $claimId)->first();

        return $row instanceof stdClass ? $this->claimFromRow($row) : null;
    }

    public function unresolved(): array
    {
        $rows = $this->connection->table($this->table)
            ->whereIn('status', [ExecutionClaimStatus::Claimed->value, ExecutionClaimStatus::Indeterminate->value])
            ->orderBy('updated_at')
            ->get();
        $claims = [];

        foreach ($rows as $row) {
            $claims[] = $this->claimFromRow($row);
        }

        return $claims;
    }

    private function claimExisting(ExecutionClaim $existing, DateTimeImmutable $at): ExecutionClaimTransition
    {
        if ($existing->status === ExecutionClaimStatus::Released) {
            $this->connection->table($this->table)
                ->where('id', $existing->id)
                ->update([
                    'status' => ExecutionClaimStatus::Claimed->value,
                    'attempt_count' => $existing->attemptCount + 1,
                    'claimed_at' => $at,
                    'completed_at' => null,
                    'indeterminate_at' => null,
                    // Clear the prior resolution's audit fields — the row is actively Claimed again,
                    // so leaving released_at/resolved_by/resolution_reason set would make it read as
                    // "released at T by X" while claimed (#355).
                    'released_at' => null,
                    'resolved_by' => null,
                    'resolution_reason' => null,
                    'updated_at' => $at,
                ]);

            return ExecutionClaimTransition::to(ExecutionClaimOutcome::Claimed, $this->findLocked($existing->id));
        }

        return ExecutionClaimTransition::to($this->duplicateOutcome($existing->status), $existing);
    }

    private function transitionClaimed(
        string $claimId,
        ExecutionClaimStatus $status,
        DateTimeImmutable $at,
    ): ExecutionClaimTransition {
        return SecurityStateTransaction::run($this->connection, 'transition an execution claim', function () use ($claimId, $status, $at): ExecutionClaimTransition {
            $claim = $this->findLocked($claimId);

            if ($claim === null) {
                return ExecutionClaimTransition::to(ExecutionClaimOutcome::NotFound);
            }

            if ($claim->status !== ExecutionClaimStatus::Claimed) {
                return ExecutionClaimTransition::to(ExecutionClaimOutcome::InvalidState, $claim);
            }

            $timestamp = $status === ExecutionClaimStatus::Completed ? 'completed_at' : 'indeterminate_at';
            $this->connection->table($this->table)
                ->where('id', $claimId)
                ->update([
                    'status' => $status->value,
                    $timestamp => $at,
                    'updated_at' => $at,
                ]);

            return ExecutionClaimTransition::to(
                $status === ExecutionClaimStatus::Completed
                    ? ExecutionClaimOutcome::Completed
                    : ExecutionClaimOutcome::Indeterminate,
                $this->findLocked($claimId),
            );
        });
    }

    private function duplicateOutcome(ExecutionClaimStatus $status): ExecutionClaimOutcome
    {
        return match ($status) {
            ExecutionClaimStatus::Claimed => ExecutionClaimOutcome::DuplicateClaimed,
            ExecutionClaimStatus::Completed => ExecutionClaimOutcome::DuplicateCompleted,
            ExecutionClaimStatus::Indeterminate => ExecutionClaimOutcome::DuplicateIndeterminate,
            ExecutionClaimStatus::Released => ExecutionClaimOutcome::InvalidState,
        };
    }

    private function findLockedByBinding(string $fingerprint): ?ExecutionClaim
    {
        $row = $this->connection->table($this->table)
            ->where('binding_fingerprint', $fingerprint)
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->claimFromRow($row) : null;
    }

    private function findLocked(string $claimId): ?ExecutionClaim
    {
        $row = $this->connection->table($this->table)
            ->where('id', $claimId)
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->claimFromRow($row) : null;
    }

    /** @return array<string, mixed> */
    private function attributes(ExecutionClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'capability' => $claim->capability,
            'policy' => $claim->policy,
            'binding_fingerprint' => $claim->bindingFingerprint,
            'status' => $claim->status->value,
            'attempt_count' => $claim->attemptCount,
            'claimed_at' => $claim->claimedAt,
            'completed_at' => $claim->completedAt,
            'indeterminate_at' => $claim->indeterminateAt,
            'released_at' => $claim->releasedAt,
            'resolved_by' => $claim->resolvedBy,
            'resolution_reason' => $claim->resolutionReason,
            'created_at' => $claim->createdAt,
            'updated_at' => $claim->updatedAt,
        ];
    }

    private function claimFromRow(stdClass $row): ExecutionClaim
    {
        return new ExecutionClaim(
            id: (string) $row->id,
            capability: (string) $row->capability,
            policy: (string) $row->policy,
            bindingFingerprint: (string) $row->binding_fingerprint,
            status: ExecutionClaimStatus::from((string) $row->status),
            attemptCount: (int) $row->attempt_count,
            claimedAt: $this->dateFromDatabase($row->claimed_at),
            completedAt: $row->completed_at === null ? null : $this->dateFromDatabase($row->completed_at),
            indeterminateAt: $row->indeterminate_at === null ? null : $this->dateFromDatabase($row->indeterminate_at),
            releasedAt: $row->released_at === null ? null : $this->dateFromDatabase($row->released_at),
            resolvedBy: $row->resolved_by === null ? null : (string) $row->resolved_by,
            resolutionReason: $row->resolution_reason === null ? null : (string) $row->resolution_reason,
            createdAt: $this->dateFromDatabase($row->created_at),
            updatedAt: $this->dateFromDatabase($row->updated_at),
        );
    }

    private function dateFromDatabase(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
