<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

enum ApprovalEvidencePhase: string
{
    case ProposalValidation = 'proposal_validation';
    case ExecutionValidation = 'execution_validation';
    case Consumption = 'consumption';
}
