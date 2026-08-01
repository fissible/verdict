<?php

declare(strict_types=1);

namespace Fissible\Verdict\ExecutionClaims;

use Fissible\Verdict\Decisions\Decision;

final readonly class ExecutionClaimAdmission
{
    public function __construct(
        public ExecutionClaimTransition $transition,
        public Decision $decision,
    ) {}

    public function admitted(): bool
    {
        return $this->transition->admitted() && $this->decision->permitsExecution();
    }

    public function claim(): ?ExecutionClaim
    {
        return $this->transition->claim;
    }
}
