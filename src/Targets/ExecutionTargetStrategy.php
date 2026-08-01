<?php

declare(strict_types=1);

namespace Fissible\Verdict\Targets;

enum ExecutionTargetStrategy: string
{
    case Refresh = 'refresh';
    case AcceptStaleSnapshot = 'accept_stale_snapshot';
}
