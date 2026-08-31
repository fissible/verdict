<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

enum ReviewDecisionKind: string
{
    case Approve = 'approve';
    case Reject = 'reject';
}
