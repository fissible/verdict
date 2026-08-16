<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use Fissible\Verdict\Contracts\ProvidesVerdictIdentity;
use Fissible\Verdict\Decisions\Evaluation;
use InvalidArgumentException;

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
        public ?string $invocationId = null,
        public ?string $toolKind = null,
        public ?string $configurationFingerprint = null,
        public ?string $actorFingerprint = null,
        public ?string $subjectFingerprint = null,
        /**
         * Which channel this capability's target resolver reads from — `context` or `proposal`.
         *
         * Recorded per decision rather than folded into the configuration fingerprint, because an
         * auditor filtering for proposal-resolved consequential capabilities needs a queryable
         * value, not a hash they must recompute to interpret. It names the constructor that was
         * used, never a verified property of the closure body. See
         * [ADR 0025](../../docs/adr/0025-target-provenance-is-proven-where-it-can-be.md).
         */
        public ?string $targetSource = null,
    ) {
        if ($this->invocationId !== null) {
            ProvenanceEntry::assertIdentifier($this->invocationId, 'Invocation');
        }
    }

    public static function fromEvaluation(Evaluation $evaluation, ?string $invocationId = null): self
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
            invocationId: $invocationId,
            // Read from the envelope's proposal metadata, not $evaluation->decision->metadata like
            // the fields above: tool_kind identifies which Verdict Laravel AI tool primitive
            // (GuardedTool/BoundTool) produced the whole envelope, so it's true for every stage of
            // that envelope's evaluation, not scoped to one decision the way approval_phase is.
            toolKind: is_string($evaluation->envelope->proposal->metadata['tool_kind'] ?? null)
                ? $evaluation->envelope->proposal->metadata['tool_kind']
                : null,
            // Not every evaluation resolves a Capability (e.g. an unregistered-capability denial),
            // so this is null exactly when $evaluation->capability is null.
            configurationFingerprint: $evaluation->capability?->configurationFingerprint(),
            targetSource: $evaluation->capability?->targetSource->value,
            actorFingerprint: self::identityFingerprint($evaluation->envelope->context->actor),
            subjectFingerprint: self::identityFingerprint($evaluation->envelope->context->subject),
        );
    }

    private static function identityFingerprint(mixed $identity): ?string
    {
        if (! $identity instanceof ProvidesVerdictIdentity) {
            return null;
        }

        $canonicalIdentity = $identity->verdictIdentity();

        if ($canonicalIdentity === '') {
            throw new InvalidArgumentException('Verdict identities must not be empty.');
        }

        return hash('sha256', $canonicalIdentity);
    }
}
