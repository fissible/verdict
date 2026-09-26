<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use Closure;
use DateInterval;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApproverSummaryMaterializer;
use Fissible\Verdict\Approvals\ApproverSummaryRelease;
use Fissible\Verdict\Approvals\IssuanceRefusalReason;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\AttestsIssuance;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\IssuesAdmittedReviewRequests;
use Fissible\Verdict\Contracts\ReviewDecisionAuthorizer;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalOperation;
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\Verdict\Exceptions\AttestedIssuanceAppendFailed;
use Fissible\Verdict\Exceptions\ReviewAuthorizerMissing;
use Fissible\Verdict\Support\ApproverSummary;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class ReviewManager
{
    public function __construct(
        private ReviewRequestStore $reviews,
        private Clock $clock,
        private ReviewDecisionAuthorizer|Closure|null $authorizer = null,
        private int $defaultTtlSeconds = 900,
        private ?EvidenceWriter $evidence = null,
        private ?InvocationContext $invocations = null,
        private ?Dispatcher $events = null,
        private ?ApproverSummaryMaterializer $summaries = null,
        private ?AttestsIssuance $attestedIssuance = null,
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

        $binding = $capability->approvalBinding($evaluation->envelope, $evaluation->target);
        $materialization = $this->summaries?->materialize(
            $capability->approverDescription($evaluation->envelope, $evaluation->target, $binding),
        );
        $id = Str::random(64);
        $summary = $materialization?->summary;
        $now = $this->clock->now();
        $ttl = $capability->confirmationTtlSeconds() ?? $this->defaultTtlSeconds;
        $request = ReviewRequest::pending(
            id: $id,
            capability: $capability->name,
            bindingFingerprint: $this->fingerprint($evaluation, $binding),
            approvalContext: $evaluation->envelope->context->approvalContext,
            createdAt: $now,
            expiresAt: $now->add(new DateInterval("PT{$ttl}S")),
            reason: $evaluation->decision->reason,
            provenance: null,
            approverSummary: $summary,
        );

        if ($capability->attestedIssuanceRequirement()) {
            // Cheap pre-conditions that refuse without touching admission and without attesting: a
            // summary that was not released, and a missing attest backend. Ordering the attest after
            // the admission check needs the opt-in seam; without it a custom store cannot honour
            // check → attest → persist, so a strict issuance fails closed rather than reverting to
            // the buggy attest-before-issue order (ADR 0039, the review-lane analog of #513).
            if ($materialization?->release !== ApproverSummaryRelease::Released || $materialization->summary === null) {
                return ReviewTransition::to(
                    ReviewOutcome::IssuanceRefused,
                    refusalReason: IssuanceRefusalReason::SummaryNotReleased,
                );
            }

            if ($this->attestedIssuance === null) {
                return ReviewTransition::to(
                    ReviewOutcome::IssuanceRefused,
                    refusalReason: IssuanceRefusalReason::AttestNotConfigured,
                );
            }

            if (! $this->reviews instanceof IssuesAdmittedReviewRequests) {
                return ReviewTransition::to(
                    ReviewOutcome::IssuanceRefused,
                    refusalReason: IssuanceRefusalReason::AttestOrderingUnsupported,
                );
            }

            // The summary is narrowed non-null immediately above; the attested-issuance path takes
            // it by non-nullable type so the in-transaction attest closure never depends on flow
            // narrowing (ADR 0039 §"check → (attest) → persist, in that order").
            $transition = $this->issueAttested($this->reviews, $request, $this->attestedIssuance, $id, $materialization->summary);
        } else {
            $transition = $this->reviews->issue($request);
        }

        return $this->recordOperation($transition, ReviewOutcome::Issued, ApprovalOperation::Issued);
    }

    /**
     * Run a strict review issuance with the attest anchored at the store's clear-to-mint point,
     * inside the admission-locked transaction. Every value is non-null by type, so the attest
     * closure never depends on flow narrowing. The request id is fixed, so a transaction retry
     * re-runs the closure with the identical sha256($id); an attest failure is wrapped so a genuine
     * store error out of the same transaction is not misclassified as AttestAppendFailed.
     */
    private function issueAttested(
        IssuesAdmittedReviewRequests $reviews,
        ReviewRequest $request,
        AttestsIssuance $attester,
        string $id,
        ApproverSummary $summary,
    ): ReviewTransition {
        try {
            return $reviews->issueAdmitted($request, function () use ($attester, $id, $summary): void {
                try {
                    $attester->attestIssuedSummary(ApprovalLane::Review, hash('sha256', $id), $summary);
                } catch (Throwable $e) {
                    throw AttestedIssuanceAppendFailed::from($e);
                }
            });
        } catch (AttestedIssuanceAppendFailed) {
            // The transaction rolled back — nothing was persisted.
            return ReviewTransition::to(
                ReviewOutcome::IssuanceRefused,
                refusalReason: IssuanceRefusalReason::AttestAppendFailed,
            );
        }
    }

    public function approve(string $requestId, string $resolvedBy): ReviewTransition
    {
        $this->validateDecisionInput($requestId, $resolvedBy);

        $unauthorized = $this->unauthorized($requestId, ReviewDecisionKind::Approve, $resolvedBy);

        if ($unauthorized !== null) {
            return $unauthorized;
        }

        return $this->recordOperation($this->reviews->approve($requestId, $resolvedBy, $this->clock->now()), ReviewOutcome::Approved, ApprovalOperation::Approved);
    }

    public function reject(string $requestId, string $resolvedBy): ReviewTransition
    {
        $this->validateDecisionInput($requestId, $resolvedBy);

        $unauthorized = $this->unauthorized($requestId, ReviewDecisionKind::Reject, $resolvedBy);

        if ($unauthorized !== null) {
            return $unauthorized;
        }

        return $this->recordOperation($this->reviews->reject($requestId, $resolvedBy, $this->clock->now()), ReviewOutcome::Rejected, ApprovalOperation::Rejected);
    }

    public function validate(Evaluation $evaluation): ReviewTransition
    {
        $stateFailure = $this->executionStateFailure($evaluation);

        if ($stateFailure !== null) {
            return $stateFailure;
        }

        /** @var Capability $capability */
        $capability = $evaluation->capability;

        return $this->reviews->validate($capability->name, $this->fingerprint($evaluation), $this->clock->now());
    }

    public function consume(Evaluation $evaluation): ReviewTransition
    {
        $stateFailure = $this->executionStateFailure($evaluation);

        if ($stateFailure !== null) {
            return $stateFailure;
        }

        /** @var Capability $capability */
        $capability = $evaluation->capability;

        return $this->recordOperation($this->reviews->consume($capability->name, $this->fingerprint($evaluation), $this->clock->now()), ReviewOutcome::Consumed, ApprovalOperation::Consumed);
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

    /** @param ?array<string, mixed> $binding */
    private function fingerprint(Evaluation $evaluation, ?array $binding = null): string
    {
        $capability = $evaluation->capability;

        if ($capability === null) {
            throw new InvalidArgumentException('A review fingerprint requires a resolved capability.');
        }

        $payload = [
            'capability' => $capability->name,
            'execution_target_policy' => $capability->executionTargetPolicy()?->name,
            'arguments' => $evaluation->envelope->proposal->arguments,
            'binding' => $binding ?? $capability->approvalBinding($evaluation->envelope, $evaluation->target),
        ];

        $approvalContext = $evaluation->envelope->context->approvalContext;

        if ($approvalContext !== []) {
            $payload['approval_context'] = $approvalContext;
        }

        return ArgumentFingerprint::make($payload);
    }

    private function recordOperation(
        ReviewTransition $transition,
        ReviewOutcome $successOutcome,
        ApprovalOperation $operation,
    ): ReviewTransition {
        $request = $transition->request;

        if ($this->evidence === null || $transition->outcome !== $successOutcome || $request === null) {
            return $transition;
        }

        try {
            $this->evidence->recordApprovalOperation(new ApprovalOperationEvidence(
                lane: ApprovalLane::Review,
                operation: $operation,
                capability: $request->capability,
                identityFingerprint: hash('sha256', $request->id),
                summaryFingerprint: $request->approverSummary?->fingerprint,
                occurredAt: $this->clock->now(),
                invocationId: $this->invocations?->current(),
            ));
        } catch (Throwable $e) {
            try {
                $this->events?->dispatch(new EvidenceWriteFailed(
                    $request->capability,
                    $operation->value,
                    $this->invocations?->current(),
                    $e->getMessage(),
                ));
            } catch (Throwable) {
                // An alert listener failing must not block the caller either.
            }
        }

        return $transition;
    }
}
