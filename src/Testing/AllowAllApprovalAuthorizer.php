<?php

declare(strict_types=1);

namespace Fissible\Verdict\Testing;

use Fissible\Verdict\Approvals\ApprovalDecisionKind;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer;

/**
 * FOR TEST SUITES ONLY. Approval decisions are fail-closed, and
 * CapabilitySecurityTestKit::assertApprovalBindingInvalidation() decides a receipt — so a test
 * environment needs some authorizer configured. This one authorizes everything: it exercises
 * receipt state machinery while deliberately not testing per-receipt authorization, which your
 * own authorizer's tests should cover. Configuring it outside a test environment removes the
 * control this package refuses to run without; verdict:validate warns when it is configured
 * anywhere but the local and testing environments.
 */
final class AllowAllApprovalAuthorizer implements ApprovalDecisionAuthorizer
{
    public function authorize(ApprovalReceipt $receipt, ApprovalDecisionKind $kind, string $decidedBy): bool
    {
        return true;
    }
}
