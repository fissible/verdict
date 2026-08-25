<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Approvals\ApprovalDecisionKind;
use Fissible\Verdict\Approvals\ApprovalReceipt;

/**
 * Decides whether a specific decision maker may finalize a specific receipt. Required:
 * ApprovalManager::approve()/reject() refuse until one is configured, because "approved_by" is
 * attestation-by-the-application — the authorizer is where the application makes it mean
 * something, typically by checking the receipt's approvalContext (tenant, conversation) against
 * what the decision maker owns. Receipts issued before approval context existed carry null there;
 * the authorizer decides what that history is worth.
 */
interface ApprovalDecisionAuthorizer
{
    public function authorize(ApprovalReceipt $receipt, ApprovalDecisionKind $kind, string $decidedBy): bool;
}
