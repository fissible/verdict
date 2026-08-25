<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

enum ApprovalDecisionKind: string
{
    case Approve = 'approve';
    case Reject = 'reject';
}
