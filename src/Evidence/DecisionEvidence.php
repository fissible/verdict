<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Decisions\Evaluation;

final readonly class DecisionEvidence
{
    /**
     * What this record asserts, as one stable, namespaced label — the record's *semantic* identity.
     *
     * Derived rather than supplied, so a caller cannot mint a claim the evaluation did not make.
     * Null only when the stage/disposition tuple is outside the vocabulary, which
     * `ClaimTypeVocabularyTest` prevents for every tuple the state machine can emit.
     */
    public ?ClaimType $claimType;

    /**
     * This record's content-derived, Attest-independent identity: `canonicaljson-sha256:<hash>`.
     *
     * Derived in the constructor for the same reason as `claimType`, and because an identity a
     * caller could pass in is not an identity. See {@see RecordDigest} for the field set, the
     * exclusions, and why Attest protects this value rather than defining it.
     */
    public string $recordDigest;

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
        /**
         * The tool description fingerprinted at wiring time, and the one last advertised to the
         * model. `toolDescriptionMatched` is the comparison made explicit, so an operator reading
         * evidence after an incident does not have to know it was worth making — and is null, not
         * false, when the description was never advertised. See #163.
         */
        public ?string $toolDescriptionFingerprint = null,
        public ?string $invocationToolDescriptionFingerprint = null,
        public ?bool $toolDescriptionMatched = null,
        /**
         * The write-ahead intent record this outcome references, when the intent lever was on for
         * this action (#160). A correlation pointer into the operational layer, deliberately
         * outside the record digest (see {@see RecordDigest}): the digest is this record's content
         * identity, and the intent row's own integrity lives in the layer that gates on it.
         */
        public ?string $intentId = null,
    ) {
        if ($this->invocationId !== null) {
            ProvenanceEntry::assertIdentifier($this->invocationId, 'Invocation');
        }

        // Both are derived from fields already assigned above. The approval phase and execution-claim
        // status are passed to the vocabulary because two stages record several distinct events
        // behind one stage/disposition pair — see ClaimType::discriminatorFor().
        $this->claimType = ClaimType::for($this->stage, $this->disposition, match ($this->stage) {
            'approval' => $this->approvalPhase,
            'execution_claim' => $this->executionClaimStatus,
            default => null,
        });

        $this->recordDigest = RecordDigest::for($this);
    }

    public static function fromEvaluation(Evaluation $evaluation, ?string $invocationId = null, ?string $intentId = null): self
    {
        return new self(
            intentId: $intentId,
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
            // This timezone-naive column is formatted in the object's zone by Laravel bindings, so mint UTC.
            recordedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
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
            toolDescriptionFingerprint: self::metadataString($evaluation, 'tool_description_fingerprint'),
            invocationToolDescriptionFingerprint: self::metadataString($evaluation, 'invocation_tool_description_fingerprint'),
            toolDescriptionMatched: self::toolDescriptionMatched($evaluation),
            actorFingerprint: self::identityFingerprint($evaluation->envelope->context->actor),
            subjectFingerprint: self::identityFingerprint($evaluation->envelope->context->subject),
        );
    }

    private static function metadataString(Evaluation $evaluation, string $key): ?string
    {
        $value = $evaluation->envelope->proposal->metadata[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Null when the description was never advertised: that is an absent observation, and reporting
     * it as a match would claim one nobody made.
     */
    private static function toolDescriptionMatched(Evaluation $evaluation): ?bool
    {
        $configured = self::metadataString($evaluation, 'tool_description_fingerprint');
        $advertised = self::metadataString($evaluation, 'invocation_tool_description_fingerprint');

        return $configured === null || $advertised === null
            ? null
            : $configured === $advertised;
    }

    private static function identityFingerprint(mixed $identity): ?string
    {
        return IdentityFingerprint::for($identity);
    }
}
