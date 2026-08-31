<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use Fissible\Verdict\Contracts\ReviewDecisionAuthorizer;
use Fissible\Verdict\Reviews\ReviewDecisionKind;
use RuntimeException;

final class ReviewAuthorizerMissing extends RuntimeException
{
    public static function forDecision(ReviewDecisionKind $kind): self
    {
        return new self(
            "Verdict refuses to {$kind->value} a review request because no review decision "
            .'authorizer is configured. Review decisions are fail-closed: set '
            .'[verdict.reviews.authorizer] to a class implementing '
            .ReviewDecisionAuthorizer::class.' that verifies the request belongs to a '
            .'conversation the decision maker may decide.',
        );
    }
}
