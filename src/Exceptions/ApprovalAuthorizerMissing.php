<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use Fissible\Verdict\Approvals\ApprovalDecisionKind;
use Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer;
use RuntimeException;

final class ApprovalAuthorizerMissing extends RuntimeException
{
    public static function forDecision(ApprovalDecisionKind $kind): self
    {
        return new self(
            "Verdict refuses to {$kind->value} an approval receipt because no approval decision "
            .'authorizer is configured. Approval decisions are fail-closed: set '
            .'[verdict.approvals.authorizer] to a class implementing '
            .ApprovalDecisionAuthorizer::class.' that verifies the receipt belongs to a '
            .'conversation the decision maker may decide. Running [verdict:make-approval-flow] '
            .'publishes a working example.',
        );
    }
}
