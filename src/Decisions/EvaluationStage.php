<?php

declare(strict_types=1);

namespace Fissible\Verdict\Decisions;

enum EvaluationStage: string
{
    case Proposal = 'proposal';
    case Approval = 'approval';
    case TargetRefresh = 'target_refresh';
    case Execution = 'execution';
    case Intent = 'intent';
    case IntentConcluded = 'intent_concluded';
    case RateLimit = 'rate_limit';
    case ExecutionClaim = 'execution_claim';
}
