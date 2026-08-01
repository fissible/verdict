<?php

declare(strict_types=1);

namespace Fissible\Verdict\ExecutionClaims;

use DateTimeImmutable;
use Fissible\Verdict\Contracts\ExecutionClaimStore;

/** Process-local storage for tests and local development only. */
final class InMemoryExecutionClaimStore implements ExecutionClaimStore
{
    /** @var array<string, ExecutionClaim> */
    private array $claims = [];

    public function claim(ExecutionClaim $claim): ExecutionClaimTransition
    {
        $existing = $this->findByBinding($claim->bindingFingerprint);

        if ($existing === null) {
            $this->claims[$claim->id] = $claim;

            return ExecutionClaimTransition::to(ExecutionClaimOutcome::Claimed, $claim);
        }

        if ($existing->status === ExecutionClaimStatus::Released) {
            $reclaimed = new ExecutionClaim(
                id: $existing->id,
                capability: $existing->capability,
                policy: $existing->policy,
                bindingFingerprint: $existing->bindingFingerprint,
                status: ExecutionClaimStatus::Claimed,
                attemptCount: $existing->attemptCount + 1,
                claimedAt: $claim->claimedAt,
                completedAt: null,
                indeterminateAt: null,
                releasedAt: $existing->releasedAt,
                resolvedBy: $existing->resolvedBy,
                resolutionReason: $existing->resolutionReason,
                createdAt: $existing->createdAt,
                updatedAt: $claim->updatedAt,
            );
            $this->claims[$existing->id] = $reclaimed;

            return ExecutionClaimTransition::to(ExecutionClaimOutcome::Claimed, $reclaimed);
        }

        return ExecutionClaimTransition::to($this->duplicateOutcome($existing->status), $existing);
    }

    public function complete(string $claimId, DateTimeImmutable $at): ExecutionClaimTransition
    {
        $claim = $this->claims[$claimId] ?? null;

        if ($claim === null) {
            return ExecutionClaimTransition::to(ExecutionClaimOutcome::NotFound);
        }

        if ($claim->status !== ExecutionClaimStatus::Claimed) {
            return ExecutionClaimTransition::to(ExecutionClaimOutcome::InvalidState, $claim);
        }

        return $this->replace($claim, ExecutionClaimStatus::Completed, $at);
    }

    public function markIndeterminate(string $claimId, DateTimeImmutable $at): ExecutionClaimTransition
    {
        $claim = $this->claims[$claimId] ?? null;

        if ($claim === null) {
            return ExecutionClaimTransition::to(ExecutionClaimOutcome::NotFound);
        }

        if ($claim->status !== ExecutionClaimStatus::Claimed) {
            return ExecutionClaimTransition::to(ExecutionClaimOutcome::InvalidState, $claim);
        }

        return $this->replace($claim, ExecutionClaimStatus::Indeterminate, $at);
    }

    public function resolve(
        string $claimId,
        ExecutionClaimResolution $resolution,
        string $resolvedBy,
        string $reason,
        DateTimeImmutable $at,
    ): ExecutionClaimTransition {
        $claim = $this->claims[$claimId] ?? null;

        if ($claim === null) {
            return ExecutionClaimTransition::to(ExecutionClaimOutcome::NotFound);
        }

        if (! in_array($claim->status, [ExecutionClaimStatus::Claimed, ExecutionClaimStatus::Indeterminate], true)) {
            return ExecutionClaimTransition::to(ExecutionClaimOutcome::InvalidState, $claim);
        }

        $status = $resolution === ExecutionClaimResolution::Completed
            ? ExecutionClaimStatus::Completed
            : ExecutionClaimStatus::Released;
        $resolved = new ExecutionClaim(
            id: $claim->id,
            capability: $claim->capability,
            policy: $claim->policy,
            bindingFingerprint: $claim->bindingFingerprint,
            status: $status,
            attemptCount: $claim->attemptCount,
            claimedAt: $claim->claimedAt,
            completedAt: $status === ExecutionClaimStatus::Completed ? $at : $claim->completedAt,
            indeterminateAt: $claim->indeterminateAt,
            releasedAt: $status === ExecutionClaimStatus::Released ? $at : $claim->releasedAt,
            resolvedBy: $resolvedBy,
            resolutionReason: $reason,
            createdAt: $claim->createdAt,
            updatedAt: $at,
        );
        $this->claims[$claimId] = $resolved;

        return ExecutionClaimTransition::to(
            $status === ExecutionClaimStatus::Completed
                ? ExecutionClaimOutcome::Completed
                : ExecutionClaimOutcome::Released,
            $resolved,
        );
    }

    public function find(string $claimId): ?ExecutionClaim
    {
        return $this->claims[$claimId] ?? null;
    }

    public function unresolved(): array
    {
        return array_values(array_filter(
            $this->claims,
            fn (ExecutionClaim $claim): bool => in_array(
                $claim->status,
                [ExecutionClaimStatus::Claimed, ExecutionClaimStatus::Indeterminate],
                true,
            ),
        ));
    }

    private function findByBinding(string $fingerprint): ?ExecutionClaim
    {
        foreach ($this->claims as $claim) {
            if (hash_equals($claim->bindingFingerprint, $fingerprint)) {
                return $claim;
            }
        }

        return null;
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

    private function replace(
        ExecutionClaim $claim,
        ExecutionClaimStatus $status,
        DateTimeImmutable $at,
    ): ExecutionClaimTransition {
        $updated = new ExecutionClaim(
            id: $claim->id,
            capability: $claim->capability,
            policy: $claim->policy,
            bindingFingerprint: $claim->bindingFingerprint,
            status: $status,
            attemptCount: $claim->attemptCount,
            claimedAt: $claim->claimedAt,
            completedAt: $status === ExecutionClaimStatus::Completed ? $at : $claim->completedAt,
            indeterminateAt: $status === ExecutionClaimStatus::Indeterminate ? $at : $claim->indeterminateAt,
            releasedAt: $claim->releasedAt,
            resolvedBy: $claim->resolvedBy,
            resolutionReason: $claim->resolutionReason,
            createdAt: $claim->createdAt,
            updatedAt: $at,
        );
        $this->claims[$claim->id] = $updated;

        return ExecutionClaimTransition::to(
            $status === ExecutionClaimStatus::Completed
                ? ExecutionClaimOutcome::Completed
                : ExecutionClaimOutcome::Indeterminate,
            $updated,
        );
    }
}
