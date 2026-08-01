<?php

declare(strict_types=1);

namespace Fissible\Verdict\ExecutionClaims;

enum ExecutionClaimResolution: string
{
    case Completed = 'completed';
    case Retryable = 'retryable';
}
