<?php

declare(strict_types=1);

namespace Fissible\Verdict\ExecutionClaims;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final readonly class ExecutionClaimManager
{
    public function __construct(
        private ExecutionClaimStore $store,
        private Clock $clock,
    ) {}

    public function claim(Capability $capability, ActionEnvelope $envelope, mixed $target): ExecutionClaimAdmission
    {
        $policy = $capability->executionClaimPolicy();

        if ($policy === null) {
            throw new LogicException('An execution claim requires an at-most-once capability policy.');
        }

        $now = $this->clock->now();
        $transition = $this->store->claim(new ExecutionClaim(
            id: Str::random(64),
            capability: $capability->name,
            policy: $policy->name,
            bindingFingerprint: ArgumentFingerprint::make([
                'capability' => $capability->name,
                'policy' => $policy->name,
                'binding' => $policy->binding($envelope, $target),
            ]),
            status: ExecutionClaimStatus::Claimed,
            attemptCount: 1,
            claimedAt: $now,
            completedAt: null,
            indeterminateAt: null,
            releasedAt: null,
            resolvedBy: null,
            resolutionReason: null,
            createdAt: $now,
            updatedAt: $now,
        ));
        $claim = $transition->claim;
        $metadata = $claim === null ? [] : $this->metadata($claim);
        $decision = $transition->admitted()
            ? Decision::permit('At-most-once execution claim admitted.', $metadata)
            : Decision::deny($this->denialReason($transition->outcome), $metadata);

        return new ExecutionClaimAdmission($transition, $decision);
    }

    public function complete(ExecutionClaimAdmission $admission): Decision
    {
        $claim = $admission->claim();

        if (! $admission->admitted() || $claim === null) {
            throw new LogicException('Only an admitted execution claim may be completed.');
        }

        $transition = $this->store->complete($claim->id, $this->clock->now());

        if ($transition->outcome !== ExecutionClaimOutcome::Completed || $transition->claim === null) {
            throw new LogicException('The execution claim could not be marked completed.');
        }

        return Decision::permit('At-most-once execution claim completed.', $this->metadata($transition->claim));
    }

    public function markIndeterminate(ExecutionClaimAdmission $admission): Decision
    {
        $claim = $admission->claim();

        if (! $admission->admitted() || $claim === null) {
            throw new LogicException('Only an admitted execution claim may become indeterminate.');
        }

        $transition = $this->store->markIndeterminate($claim->id, $this->clock->now());

        if ($transition->outcome !== ExecutionClaimOutcome::Indeterminate || $transition->claim === null) {
            throw new LogicException('The execution claim could not be marked indeterminate.');
        }

        return Decision::deny('Executor failed after admission; claim requires reconciliation.', $this->metadata($transition->claim));
    }

    /** @return list<ExecutionClaim> */
    public function unresolved(): array
    {
        return $this->store->unresolved();
    }

    public function find(string $claimId): ?ExecutionClaim
    {
        return $this->store->find($claimId);
    }

    public function resolve(
        string $claimId,
        ExecutionClaimResolution $resolution,
        string $resolvedBy,
        string $reason,
    ): ExecutionClaimTransition {
        if (blank($claimId) || blank($resolvedBy) || blank($reason)) {
            throw new InvalidArgumentException('Claim, operator, and reconciliation reason are required.');
        }

        return $this->store->resolve(
            $claimId,
            $resolution,
            $resolvedBy,
            $reason,
            $this->clock->now(),
        );
    }

    /** @return array<string, mixed> */
    private function metadata(ExecutionClaim $claim): array
    {
        return [
            'execution_claim_fingerprint' => hash('sha256', $claim->id),
            'execution_claim_binding_fingerprint' => $claim->bindingFingerprint,
            'execution_claim_policy' => $claim->policy,
            'execution_claim_status' => $claim->status->value,
            'execution_claim_attempt' => $claim->attemptCount,
        ];
    }

    private function denialReason(ExecutionClaimOutcome $outcome): string
    {
        return match ($outcome) {
            ExecutionClaimOutcome::DuplicateClaimed => 'Logical operation already has an active execution claim.',
            ExecutionClaimOutcome::DuplicateCompleted => 'Logical operation was already completed.',
            ExecutionClaimOutcome::DuplicateIndeterminate => 'Logical operation has an indeterminate execution outcome.',
            default => 'Logical operation could not obtain an execution claim.',
        };
    }
}
