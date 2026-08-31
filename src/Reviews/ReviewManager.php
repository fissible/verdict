<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use Closure;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\ReviewDecisionAuthorizer;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Exceptions\ReviewAuthorizerMissing;
use InvalidArgumentException;

final readonly class ReviewManager
{
    public function __construct(
        private ReviewRequestStore $reviews,
        private Clock $clock,
        private ReviewDecisionAuthorizer|Closure|null $authorizer = null,
    ) {}

    public function approve(string $requestId, string $resolvedBy): ReviewTransition
    {
        $this->validateDecisionInput($requestId, $resolvedBy);

        $unauthorized = $this->unauthorized($requestId, ReviewDecisionKind::Approve, $resolvedBy);

        if ($unauthorized !== null) {
            return $unauthorized;
        }

        return $this->reviews->approve($requestId, $resolvedBy, $this->clock->now());
    }

    public function reject(string $requestId, string $resolvedBy): ReviewTransition
    {
        $this->validateDecisionInput($requestId, $resolvedBy);

        $unauthorized = $this->unauthorized($requestId, ReviewDecisionKind::Reject, $resolvedBy);

        if ($unauthorized !== null) {
            return $unauthorized;
        }

        return $this->reviews->reject($requestId, $resolvedBy, $this->clock->now());
    }

    private function unauthorized(
        string $requestId,
        ReviewDecisionKind $kind,
        string $decidedBy,
    ): ?ReviewTransition {
        $authorizer = $this->authorizer instanceof Closure ? ($this->authorizer)() : $this->authorizer;

        if ($authorizer === null) {
            throw ReviewAuthorizerMissing::forDecision($kind);
        }

        $request = $this->reviews->find($requestId);

        if ($request === null
            || $request->status !== ReviewStatus::Pending
            || $request->isExpiredAt($this->clock->now())) {
            return null;
        }

        return $authorizer->authorize($request, $kind, $decidedBy)
            ? null
            : ReviewTransition::to(ReviewOutcome::Unauthorized);
    }

    private function validateDecisionInput(string $requestId, string $decidedBy): void
    {
        if (blank($requestId) || blank($decidedBy)) {
            throw new InvalidArgumentException('Review request and decision-maker identifiers are required.');
        }
    }
}
