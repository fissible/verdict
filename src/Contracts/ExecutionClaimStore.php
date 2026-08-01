<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use DateTimeImmutable;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimResolution;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimTransition;

interface ExecutionClaimStore
{
    public function claim(ExecutionClaim $claim): ExecutionClaimTransition;

    public function complete(string $claimId, DateTimeImmutable $at): ExecutionClaimTransition;

    public function markIndeterminate(string $claimId, DateTimeImmutable $at): ExecutionClaimTransition;

    public function resolve(
        string $claimId,
        ExecutionClaimResolution $resolution,
        string $resolvedBy,
        string $reason,
        DateTimeImmutable $at,
    ): ExecutionClaimTransition;

    public function find(string $claimId): ?ExecutionClaim;

    /** @return list<ExecutionClaim> */
    public function unresolved(): array;
}
