<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use DateTimeImmutable;
use Fissible\Verdict\Contracts\ReviewRequestStore;

/**
 * Process-local test store. It is not safe for production, Octane, or queue workers.
 */
final class InMemoryReviewRequestStore implements ReviewRequestStore
{
    /** @var array<string, ReviewRequest> */
    private array $requests = [];

    public function issue(ReviewRequest $request): ReviewTransition
    {
        $existing = $this->findForBinding($request->capability, $request->bindingFingerprint);

        if ($existing !== null) {
            if ($existing->isExpiredAt($request->createdAt)) {
                return ReviewTransition::to(ReviewOutcome::Expired, $existing);
            }

            if (! in_array($existing->status, [ReviewStatus::Pending, ReviewStatus::Approved], true)) {
                return ReviewTransition::to(ReviewOutcome::InvalidState, $existing);
            }

            return ReviewTransition::to(ReviewOutcome::Existing, $existing);
        }

        if (isset($this->requests[$request->id])) {
            return ReviewTransition::to(ReviewOutcome::InvalidState, $this->requests[$request->id]);
        }

        $this->requests[$request->id] = $request;

        return ReviewTransition::to(ReviewOutcome::Issued, $request);
    }

    public function find(string $requestId): ?ReviewRequest
    {
        return $this->requests[$requestId] ?? null;
    }

    public function approve(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
    {
        $request = $this->requests[$requestId] ?? null;
        $failure = $this->transitionFailure($request, $at);

        if ($failure !== null) {
            return $failure;
        }

        /** @var ReviewRequest $request */
        $updated = $this->replace(
            $request,
            status: ReviewStatus::Approved,
            resolvedBy: $resolvedBy,
            resolvedAt: $at,
        );

        return ReviewTransition::to(ReviewOutcome::Approved, $updated);
    }

    public function reject(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
    {
        $request = $this->requests[$requestId] ?? null;
        $failure = $this->transitionFailure($request, $at);

        if ($failure !== null) {
            return $failure;
        }

        /** @var ReviewRequest $request */
        $updated = $this->replace(
            $request,
            status: ReviewStatus::Rejected,
            resolvedBy: $resolvedBy,
            resolvedAt: $at,
        );

        return ReviewTransition::to(ReviewOutcome::Rejected, $updated);
    }

    public function validate(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
    {
        return $this->validateRequest($this->findForBinding($capability, $bindingFingerprint), $at);
    }

    public function consume(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
    {
        $request = $this->findForBinding($capability, $bindingFingerprint);
        $validation = $this->validateRequest($request, $at);

        if (! $validation->succeeded()) {
            return $validation;
        }

        /** @var ReviewRequest $request */
        $updated = $this->replace(
            $request,
            status: ReviewStatus::Consumed,
            consumedAt: $at,
        );

        return ReviewTransition::to(ReviewOutcome::Consumed, $updated);
    }

    private function validateRequest(?ReviewRequest $request, DateTimeImmutable $at): ReviewTransition
    {
        if ($request === null) {
            return ReviewTransition::to(ReviewOutcome::NotFound);
        }

        if ($request->isExpiredAt($at)) {
            return ReviewTransition::to(ReviewOutcome::Expired, $request);
        }

        if ($request->status !== ReviewStatus::Approved) {
            return ReviewTransition::to(ReviewOutcome::InvalidState, $request);
        }

        return ReviewTransition::to(ReviewOutcome::Approved, $request);
    }

    private function transitionFailure(?ReviewRequest $request, DateTimeImmutable $at): ?ReviewTransition
    {
        if ($request === null) {
            return ReviewTransition::to(ReviewOutcome::NotFound);
        }

        if ($request->isExpiredAt($at)) {
            return ReviewTransition::to(ReviewOutcome::Expired, $request);
        }

        if ($request->status !== ReviewStatus::Pending) {
            return ReviewTransition::to(ReviewOutcome::InvalidState, $request);
        }

        return null;
    }

    private function replace(
        ReviewRequest $request,
        ?ReviewStatus $status = null,
        ?string $resolvedBy = null,
        ?DateTimeImmutable $resolvedAt = null,
        ?DateTimeImmutable $consumedAt = null,
    ): ReviewRequest {
        $updated = new ReviewRequest(
            id: $request->id,
            capability: $request->capability,
            bindingFingerprint: $request->bindingFingerprint,
            approvalContext: $request->approvalContext,
            provenance: $request->provenance,
            approverSummary: $request->approverSummary,
            status: $status ?? $request->status,
            reason: $request->reason,
            createdAt: $request->createdAt,
            expiresAt: $request->expiresAt,
            resolvedBy: $resolvedBy ?? $request->resolvedBy,
            resolvedAt: $resolvedAt ?? $request->resolvedAt,
            consumedAt: $consumedAt ?? $request->consumedAt,
        );

        $this->requests[$request->id] = $updated;

        return $updated;
    }

    private function findForBinding(string $capability, string $bindingFingerprint): ?ReviewRequest
    {
        foreach ($this->requests as $request) {
            if ($request->capability === $capability
                && hash_equals($request->bindingFingerprint, $bindingFingerprint)) {
                return $request;
            }
        }

        return null;
    }
}
