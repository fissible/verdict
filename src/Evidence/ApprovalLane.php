<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

enum ApprovalLane: string
{
    case Confirmation = 'confirmation';
    case Review = 'review';
}
