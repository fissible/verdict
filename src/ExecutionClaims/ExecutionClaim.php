<?php

declare(strict_types=1);

namespace Fissible\Verdict\ExecutionClaims;

use DateTimeImmutable;

final readonly class ExecutionClaim
{
    public function __construct(
        public string $id,
        public string $capability,
        public string $policy,
        public string $bindingFingerprint,
        public ExecutionClaimStatus $status,
        public int $attemptCount,
        public DateTimeImmutable $claimedAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $indeterminateAt,
        public ?DateTimeImmutable $releasedAt,
        public ?string $resolvedBy,
        public ?string $resolutionReason,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
