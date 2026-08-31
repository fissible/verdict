<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use Closure;
use DateInterval;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\ReviewDecisionAuthorizer;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Exceptions\ReviewAuthorizerMissing;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ReviewManager
{
    public function __construct(
        private ReviewRequestStore $reviews,
        private Clock $clock,
        private ReviewDecisionAuthorizer|Closure|null $authorizer = null,
        private int $defaultTtlSeconds = 900,
    ) {
        if ($this->defaultTtlSeconds < 1) {
            throw new InvalidArgumentException('The default review request TTL must be at least one second.');
        }
    }

    public function issue(Evaluation $evaluation): ReviewTransition
    {
        if ($evaluation->decision->disposition !== Disposition::RequireReview) {
            return ReviewTransition::to(ReviewOutcome::InvalidState);
        }

        $capability = $evaluation->capability;

        if ($capability === null || ! $capability->confirmationRequired()) {
            return ReviewTransition::to(ReviewOutcome::InvalidState);
        }

        $now = $this->clock->now();
        $ttl = $capability->confirmationTtlSeconds() ?? $this->defaultTtlSeconds;
        $request = ReviewRequest::pending(
            id: Str::random(64),
            capability: $capability->name,
            bindingFingerprint: $this->fingerprint($evaluation),
            approvalContext: $evaluation->envelope->context->approvalContext,
            createdAt: $now,
            expiresAt: $now->add(new DateInterval("PT{$ttl}S")),
            reason: $evaluation->decision->reason,
            provenance: null,
            approverSummary: null,
        );

        return $this->reviews->issue($request);
    }

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

    public function validate(Evaluation $evaluation): ReviewTransition
    {
        $stateFailure = $this->executionStateFailure($evaluation);

        if ($stateFailure !== null) {
            return $stateFailure;
        }

        /** @var \Fissible\Verdict\Capabilities\Capability $capability */
        $capability = $evaluation->capability;

        return $this->reviews->validate($capability->name, $this->fingerprint($evaluation), $this->clock->now());
    }

    public function consume(Evaluation $evaluation): ReviewTransition
    {
        $stateFailure = $this->executionStateFailure($evaluation);

        if ($stateFailure !== null) {
            return $stateFailure;
        }

        /** @var \Fissible\Verdict\Capabilities\Capability $capability */
        $capability = $evaluation->capability;

        return $this->reviews->consume($capability->name, $this->fingerprint($evaluation), $this->clock->now());
    }

    private function executionStateFailure(Evaluation $evaluation): ?ReviewTransition
    {
        $capability = $evaluation->capability;

        if ($evaluation->decision->disposition !== Disposition::RequireReview
            || $capability === null
            || ! $capability->confirmationRequired()) {
            return ReviewTransition::to(ReviewOutcome::InvalidState);
        }

        return null;
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

    private function fingerprint(Evaluation $evaluation): string
    {
        $capability = $evaluation->capability;

        if ($capability === null) {
            throw new InvalidArgumentException('A review fingerprint requires a resolved capability.');
        }

        $payload = [
            'capability' => $capability->name,
            'execution_target_policy' => $capability->executionTargetPolicy()?->name,
            'arguments' => $evaluation->envelope->proposal->arguments,
            'binding' => $capability->approvalBinding($evaluation->envelope, $evaluation->target),
        ];

        $approvalContext = $evaluation->envelope->context->approvalContext;

        if ($approvalContext !== []) {
            $payload['approval_context'] = $approvalContext;
        }

        return ArgumentFingerprint::make($payload);
    }
}
