<?php

declare(strict_types=1);

namespace Fissible\Verdict\ExecutionClaims;

final readonly class ExecutionClaimTransition
{
    private function __construct(
        public ExecutionClaimOutcome $outcome,
        public ?ExecutionClaim $claim,
    ) {}

    public static function to(ExecutionClaimOutcome $outcome, ?ExecutionClaim $claim = null): self
    {
        return new self($outcome, $claim);
    }

    public function admitted(): bool
    {
        return $this->outcome === ExecutionClaimOutcome::Claimed;
    }
}
