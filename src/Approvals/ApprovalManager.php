<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateInterval;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ApprovalManager
{
    public function __construct(
        private ApprovalReceiptStore $receipts,
        private ApprovalExecutionContext $executionContext,
        private Clock $clock,
        private int $defaultTtlSeconds,
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

        return $this->receipts->reject(
            receiptId: $receiptId,
            toolCallId: $toolCallId,
            rejectedBy: $rejectedBy,
            at: $this->clock->now(),
        );
    }

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

    private function fingerprint(Evaluation $evaluation): string
    {
        $capability = $evaluation->capability;

        if ($capability === null) {
            throw new InvalidArgumentException('An approval fingerprint requires a resolved capability.');
        }

        return ArgumentFingerprint::make([
            'capability' => $capability->name,
            'execution_target_policy' => $capability->executionTargetPolicy()?->name,
            'arguments' => $evaluation->envelope->proposal->arguments,
            'binding' => $capability->approvalBinding($evaluation->envelope, $evaluation->target),
        ]);
    }

    private function validateDecisionInput(string $receiptId, string $toolCallId, string $decidedBy): void
    {
        if (blank($receiptId) || blank($toolCallId) || blank($decidedBy)) {
            throw new InvalidArgumentException('Approval receipt, tool call, and decision-maker identifiers are required.');
        }
    }
}
