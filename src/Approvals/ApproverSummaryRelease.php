<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

enum ApproverSummaryRelease: string
{
    case Released = 'released';
    case NotReleased = 'not_released';
    case ReleaseDenied = 'release_denied';
}
