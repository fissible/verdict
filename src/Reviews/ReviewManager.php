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
use Fissible\Verdict\Contracts\ReviewDecisionAuthorizer;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalOperation;
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\Verdict\Exceptions\ReviewAuthorizerMissing;
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

        if ($capability->attestedIssuanceRequirement()) {
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

            try {
                $this->attestedIssuance->attestIssuedSummary(
                    ApprovalLane::Review,
                    hash('sha256', $id),
                    $materialization->summary,
                );
            } catch (Throwable) {
                return ReviewTransition::to(
                    ReviewOutcome::IssuanceRefused,
                    refusalReason: IssuanceRefusalReason::AttestAppendFailed,
                );
            }
        }

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

        return $this->recordOperation($this->reviews->issue($request), ReviewOutcome::Issued, ApprovalOperation::Issued);
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
