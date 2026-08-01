<?php

declare(strict_types=1);

namespace Fissible\Verdict\ExecutionClaims;

enum ExecutionClaimStatus: string
{
    case Claimed = 'claimed';
    case Completed = 'completed';
    case Indeterminate = 'indeterminate';
    case Released = 'released';
}
