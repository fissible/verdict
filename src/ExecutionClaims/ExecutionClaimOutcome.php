<?php

declare(strict_types=1);

namespace Fissible\Verdict\ExecutionClaims;

enum ExecutionClaimOutcome: string
{
    case Claimed = 'claimed';
    case Completed = 'completed';
    case Indeterminate = 'indeterminate';
    case Released = 'released';
    case DuplicateClaimed = 'duplicate_claimed';
    case DuplicateCompleted = 'duplicate_completed';
    case DuplicateIndeterminate = 'duplicate_indeterminate';
    case NotFound = 'not_found';
    case InvalidState = 'invalid_state';
}
