<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Closure;
use DateInterval;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Exceptions\ApprovalAuthorizerMissing;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ApprovalManager
{
    /**
     * @internal Resolve ApprovalManager from the container. This constructor is not part of the
     *           supported surface and may gain required parameters in any release.
     *           See docs/adr/0019-verdict-services-are-container-resolved.md.
     */
    public function __construct(
        private ApprovalReceiptStore $receipts,
        private ApprovalExecutionContext $executionContext,
        private Clock $clock,
        private ApproverProvenanceRelease $approverProvenance,
        private InvocationContext $invocations,
        private int $defaultTtlSeconds,
        private ApprovalDecisionAuthorizer|Closure|null $authorizer = null,
    ) {
        if ($this->defaultTtlSeconds < 1) {
            throw new InvalidArgumentException('The default approval receipt TTL must be at least one second.');
        }
    }

    public function issue(Evaluation $evaluation): ApprovalTransition
    {
        $capability = $evaluation->capability;
        $toolCallId = $evaluation->envelope->proposal->idempotencyKey;

        if ($capability === null || ! $capability->confirmationRequired() || blank($toolCallId)) {
            return ApprovalTransition::to(ApprovalOutcome::InvalidState);
        }

        $now = $this->clock->now();
        $ttl = $capability->confirmationTtlSeconds() ?? $this->defaultTtlSeconds;
        $receipt = new ApprovalReceipt(
            id: Str::random(64),
            toolCallId: $toolCallId,
            capability: $capability->name,
            bindingFingerprint: $this->fingerprint($evaluation),
            provenance: $this->provenance($evaluation),
            approvalContext: $evaluation->envelope->context->approvalContext,
            status: ApprovalReceiptStatus::Pending,
            reason: $capability->confirmationReason(),
            expiresAt: $now->add(new DateInterval("PT{$ttl}S")),
            approvedBy: null,
            approvedAt: null,
            rejectedBy: null,
            rejectedAt: null,
            consumedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );

        return $this->receipts->issue($receipt);
    }

    public function challengeForToolCall(string $toolCallId): ?ApprovalChallenge
    {
        $receipt = $this->receipts->findForToolCall($toolCallId);

        if ($receipt === null
            || $receipt->status !== ApprovalReceiptStatus::Pending
            || $receipt->isExpiredAt($this->clock->now())) {
            return null;
        }

        return ApprovalChallenge::fromReceipt($receipt);
    }

    public function approve(string $receiptId, string $toolCallId, string $approvedBy): ApprovalTransition
    {
        $this->validateDecisionInput($receiptId, $toolCallId, $approvedBy);

        $unauthorized = $this->unauthorized($receiptId, $toolCallId, ApprovalDecisionKind::Approve, $approvedBy);

        if ($unauthorized !== null) {
            return $unauthorized;
        }

        return $this->receipts->approve(
            receiptId: $receiptId,
            toolCallId: $toolCallId,
            approvedBy: $approvedBy,
            at: $this->clock->now(),
        );
    }

    public function reject(string $receiptId, string $toolCallId, string $rejectedBy): ApprovalTransition
    {
        $this->validateDecisionInput($receiptId, $toolCallId, $rejectedBy);

        $unauthorized = $this->unauthorized($receiptId, $toolCallId, ApprovalDecisionKind::Reject, $rejectedBy);

        if ($unauthorized !== null) {
            return $unauthorized;
        }

        return $this->receipts->reject(
            receiptId: $receiptId,
            toolCallId: $toolCallId,
            rejectedBy: $rejectedBy,
            at: $this->clock->now(),
        );
    }

    /**
     * @internal Consumes (spends) the approval receipt for this evaluation, transitioning it to
     *           Consumed. VerdictManager calls this only as the final step of the full execution
     *           gate sequence — after the intent, rate-limit, and execution-claim gates have passed.
     *           Calling it directly from application code spends a receipt OUTSIDE those gates,
     *           defeating rate limiting and execution-claim exclusivity. It is not part of the
     *           supported surface; drive execution through VerdictManager, never this method.
     */
    public function consume(Evaluation $evaluation): ApprovalTransition
    {
        $stateFailure = $this->executionStateFailure($evaluation);

        if ($stateFailure !== null) {
            return $stateFailure;
        }

        /** @var string $toolCallId */
        $toolCallId = $evaluation->envelope->proposal->idempotencyKey;

        return $this->receipts->consume(
            toolCallId: $toolCallId,
            bindingFingerprint: $this->fingerprint($evaluation),
            at: $this->clock->now(),
        );
    }

    public function validate(Evaluation $evaluation): ApprovalTransition
    {
        $stateFailure = $this->executionStateFailure($evaluation);

        if ($stateFailure !== null) {
            return $stateFailure;
        }

        /** @var string $toolCallId */
        $toolCallId = $evaluation->envelope->proposal->idempotencyKey;

        return $this->receipts->validate(
            toolCallId: $toolCallId,
            bindingFingerprint: $this->fingerprint($evaluation),
            at: $this->clock->now(),
        );
    }

    /** @param Closure(): mixed $callback */
    public function withinApprovedToolCalls(ApprovedToolCalls $toolCallIds, Closure $callback): mixed
    {
        return $this->executionContext->within($toolCallIds, $callback);
    }

    private function executionStateFailure(Evaluation $evaluation): ?ApprovalTransition
    {
        $toolCallId = $evaluation->envelope->proposal->idempotencyKey;
        $capability = $evaluation->capability;

        if ($capability === null
            || ! $capability->confirmationRequired()
            || blank($toolCallId)
            || ! $this->executionContext->allows($toolCallId)) {
            return ApprovalTransition::to(ApprovalOutcome::InvalidState);
        }

        return null;
    }

    /**
     * Assembled here, while the invocation is still in scope, and carried on the receipt.
     *
     * The challenge an approver reads is built later by challengeForToolCall(), typically in the
     * approval controller's own request — a different process, with no invocation frame and no way
     * to reach the ledger entries this describes. Resolving it there would report unknown for every
     * proposal. See ADR 0026 §6.
     */
    private function provenance(Evaluation $evaluation): ProposalProvenance
    {
        return $this->approverProvenance->disclose(
            $this->invocations->current(),
            ProposalAnchor::for($evaluation->envelope->proposal->arguments),
        );
    }

    private function fingerprint(Evaluation $evaluation): string
    {
        $capability = $evaluation->capability;

        if ($capability === null) {
            throw new InvalidArgumentException('An approval fingerprint requires a resolved capability.');
        }

        // approval_context participates in the binding identity so a colliding tool-call id from
        // a different conversation gets its own receipt: the authorizer keys on this field, so a
        // receipt reachable under one context must never be validated, consumed, or reused under
        // another. The key is omitted — not hashed as [] — when no context was supplied, so an
        // application that has not adopted approvalContext produces the exact pre-capture
        // fingerprint and its receipts pending across the upgrade still validate.
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

    /**
     * Runs the required authorizer against the receipt this decision names — approval decisions
     * are fail-closed, so no configured authorizer means no decision. The store remains the single
     * authority on receipt state: after a read establishes that the receipt is pending and
     * unexpired, the authorizer may decide whether the caller can make the decision. Every other
     * receipt is delegated to the store untouched so it produces the canonical
     * NotFound/Mismatch/Expired/InvalidState outcome. The receipt is addressed by id — never by
     * findForToolCall(), whose null is ambiguous (absent OR a colliding tool-call id) and would
     * let a second receipt on the same call bypass the authorizer while the store still finalized
     * by id. The fetch-then-transition race is benign: a terminal receipt cannot become pending,
     * and the store re-validates state atomically, so a decidable pre-read is only optimistic.
     * Apart from Unauthorized, the manager originates no state outcome and returns the store's
     * transition unaltered.
     */
    private function unauthorized(
        string $receiptId,
        string $toolCallId,
        ApprovalDecisionKind $kind,
        string $decidedBy,
    ): ?ApprovalTransition {
        // A Closure defers container resolution to decision time, so a misconfigured authorizer
        // class surfaces here — in the decision path it would break — rather than failing every
        // ApprovalManager resolution including issue()/validate()/consume().
        $authorizer = $this->authorizer instanceof Closure ? ($this->authorizer)() : $this->authorizer;

        if ($authorizer === null) {
            throw ApprovalAuthorizerMissing::forDecision($kind);
        }

        $receipt = $this->receipts->find($receiptId);

        // This is delegation, not approval: only a currently decidable receipt reaches the authorizer.
        if ($receipt === null
            || $receipt->toolCallId !== $toolCallId
            || $receipt->status !== ApprovalReceiptStatus::Pending
            || $receipt->isExpiredAt($this->clock->now())) {
            return null;
        }

        return $authorizer->authorize($receipt, $kind, $decidedBy)
            ? null
            : ApprovalTransition::to(ApprovalOutcome::Unauthorized);
    }

    private function validateDecisionInput(string $receiptId, string $toolCallId, string $decidedBy): void
    {
        if (blank($receiptId) || blank($toolCallId) || blank($decidedBy)) {
            throw new InvalidArgumentException('Approval receipt, tool call, and decision-maker identifiers are required.');
        }
    }
}
