<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

enum ApproverSummaryDiagnostic: string
{
    case NoCandidate = 'no_candidate';
    case DisplayContractViolation = 'display_contract_violation';
}
