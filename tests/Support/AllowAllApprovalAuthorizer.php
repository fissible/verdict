<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Verdict\Approvals\ApprovalDecisionKind;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer;

/**
 * The suite-wide default: approval flows under test exercise receipt state machinery, not
 * per-receipt authorization, so the base TestCase configures this permissive authorizer.
 * Authorization behavior itself is tested in ApprovalDecisionAuthorizationTest, which overrides it.
 */
final class AllowAllApprovalAuthorizer implements ApprovalDecisionAuthorizer
{
    public function authorize(ApprovalReceipt $receipt, ApprovalDecisionKind $kind, string $decidedBy): bool
    {
        return true;
    }
}
