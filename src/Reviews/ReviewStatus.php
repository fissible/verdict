<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Consumed = 'consumed';
}
