<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

enum ApprovalOperation: string
{
    case Issued = 'issued';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Consumed = 'consumed';
}
