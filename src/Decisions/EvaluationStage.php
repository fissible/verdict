<?php

declare(strict_types=1);

namespace Fissible\Verdict\Decisions;

enum EvaluationStage: string
{
    case Proposal = 'proposal';
    case Approval = 'approval';
    case Execution = 'execution';
}
