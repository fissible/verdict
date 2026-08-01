<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use Fissible\Verdict\Decisions\Evaluation;

final readonly class DecisionEvidence
{
    public function __construct(
        public string $envelopeId,
        public string $capability,
        public string $stage,
        public string $disposition,
        public ?string $reason,
        public string $argumentFingerprint,
        public ?string $idempotencyKey,
        public ?string $approvalReceiptFingerprint,
        public ?string $approvalPhase,
        public ?string $approvalOutcome,
        public ?string $targetPolicy,
        public ?string $targetStrategy,
        public ?string $proposalTargetIdentityFingerprint,
        public ?string $executionTargetIdentityFingerprint,
        public ?bool $targetIdentityMatched,
        public ?string $rateLimitKeyFingerprint,
        public ?string $rateLimitPolicy,
        public ?int $rateLimitLimit,
        public ?int $rateLimitRemaining,
        public ?DateTimeImmutable $rateLimitResetAt,
        public ?string $executionClaimFingerprint,
        public ?string $executionClaimBindingFingerprint,
        public ?string $executionClaimPolicy,
        public ?string $executionClaimStatus,
        public ?int $executionClaimAttempt,
        public DateTimeImmutable $recordedAt,
    ) {}

    public static function fromEvaluation(Evaluation $evaluation): self
    {
        return new self(
            envelopeId: $evaluation->envelope->id,
            capability: $evaluation->envelope->proposal->capability,
            stage: $evaluation->stage->value,
            disposition: $evaluation->decision->disposition->value,
            reason: $evaluation->decision->reason,
            argumentFingerprint: ArgumentFingerprint::make($evaluation->envelope->proposal->arguments),
            idempotencyKey: $evaluation->envelope->proposal->idempotencyKey,
            approvalReceiptFingerprint: is_string($evaluation->decision->metadata['approval_receipt_fingerprint'] ?? null)
                ? $evaluation->decision->metadata['approval_receipt_fingerprint']
                : null,
            approvalPhase: is_string($evaluation->decision->metadata['approval_phase'] ?? null)
                ? $evaluation->decision->metadata['approval_phase']
                : null,
            approvalOutcome: is_string($evaluation->decision->metadata['approval_outcome'] ?? null)
                ? $evaluation->decision->metadata['approval_outcome']
                : null,
            targetPolicy: is_string($evaluation->decision->metadata['target_policy'] ?? null)
                ? $evaluation->decision->metadata['target_policy']
                : null,
            targetStrategy: is_string($evaluation->decision->metadata['target_strategy'] ?? null)
                ? $evaluation->decision->metadata['target_strategy']
                : null,
            proposalTargetIdentityFingerprint: is_string($evaluation->decision->metadata['proposal_target_identity_fingerprint'] ?? null)
                ? $evaluation->decision->metadata['proposal_target_identity_fingerprint']
                : null,
            executionTargetIdentityFingerprint: is_string($evaluation->decision->metadata['execution_target_identity_fingerprint'] ?? null)
                ? $evaluation->decision->metadata['execution_target_identity_fingerprint']
                : null,
            targetIdentityMatched: is_bool($evaluation->decision->metadata['target_identity_matched'] ?? null)
                ? $evaluation->decision->metadata['target_identity_matched']
                : null,
            rateLimitKeyFingerprint: is_string($evaluation->decision->metadata['rate_limit_key_fingerprint'] ?? null)
                ? $evaluation->decision->metadata['rate_limit_key_fingerprint']
                : null,
            rateLimitPolicy: is_string($evaluation->decision->metadata['rate_limit_policy'] ?? null)
                ? $evaluation->decision->metadata['rate_limit_policy']
                : null,
            rateLimitLimit: is_int($evaluation->decision->metadata['rate_limit_limit'] ?? null)
                ? $evaluation->decision->metadata['rate_limit_limit']
                : null,
            rateLimitRemaining: is_int($evaluation->decision->metadata['rate_limit_remaining'] ?? null)
                ? $evaluation->decision->metadata['rate_limit_remaining']
                : null,
            rateLimitResetAt: is_string($evaluation->decision->metadata['rate_limit_reset_at'] ?? null)
                ? new DateTimeImmutable($evaluation->decision->metadata['rate_limit_reset_at'])
                : null,
            executionClaimFingerprint: is_string($evaluation->decision->metadata['execution_claim_fingerprint'] ?? null)
                ? $evaluation->decision->metadata['execution_claim_fingerprint']
                : null,
            executionClaimBindingFingerprint: is_string($evaluation->decision->metadata['execution_claim_binding_fingerprint'] ?? null)
                ? $evaluation->decision->metadata['execution_claim_binding_fingerprint']
                : null,
            executionClaimPolicy: is_string($evaluation->decision->metadata['execution_claim_policy'] ?? null)
                ? $evaluation->decision->metadata['execution_claim_policy']
                : null,
            executionClaimStatus: is_string($evaluation->decision->metadata['execution_claim_status'] ?? null)
                ? $evaluation->decision->metadata['execution_claim_status']
                : null,
            executionClaimAttempt: is_int($evaluation->decision->metadata['execution_claim_attempt'] ?? null)
                ? $evaluation->decision->metadata['execution_claim_attempt']
                : null,
            recordedAt: new DateTimeImmutable,
        );
    }
}
