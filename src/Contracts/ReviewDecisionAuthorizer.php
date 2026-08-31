<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Reviews\ReviewDecisionKind;
use Fissible\Verdict\Reviews\ReviewRequest;

/**
 * Decides whether a specific decision maker may finalize a specific review request. Required:
 * ReviewManager::approve()/reject() refuse until one is configured, because "resolved_by" is
 * attestation-by-the-application — the authorizer is where the application makes it mean
 * something, typically by checking the request's approvalContext (tenant, conversation) against
 * what the decision maker owns. Requests issued before approval context existed carry null there;
 * the authorizer decides what that history is worth.
 */
interface ReviewDecisionAuthorizer
{
    public function authorize(ReviewRequest $request, ReviewDecisionKind $kind, string $decidedBy): bool;
}
