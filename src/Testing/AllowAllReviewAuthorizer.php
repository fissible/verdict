<?php

declare(strict_types=1);

namespace Fissible\Verdict\Testing;

use Fissible\Verdict\Contracts\ReviewDecisionAuthorizer;
use Fissible\Verdict\Reviews\ReviewDecisionKind;
use Fissible\Verdict\Reviews\ReviewRequest;

/**
 * FOR TEST SUITES ONLY. Review decisions are fail-closed, and a test environment needs some
 * authorizer configured. This one authorizes everything: it exercises request state machinery
 * while deliberately not testing per-request authorization, which your own author's tests should
 * cover. Configuring it outside a test environment removes the control this package refuses to
 * run without; verdict:validate warns when it is configured anywhere but the local and testing
 * environments.
 */
final class AllowAllReviewAuthorizer implements ReviewDecisionAuthorizer
{
    public function authorize(ReviewRequest $request, ReviewDecisionKind $kind, string $decidedBy): bool
    {
        return true;
    }
}
