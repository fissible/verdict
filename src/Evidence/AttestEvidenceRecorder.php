<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fissible\AttestLaravel\Support\AttestRegistry;
use Fissible\Verdict\Contracts\AttestsIssuance;
use Fissible\Verdict\Contracts\DurableEvidenceRecorder;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\Events\ChainWriteFailed;
use Fissible\Verdict\Exceptions\EvidenceChainWriteFailed;
use Fissible\Verdict\Support\ApproverSummary;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class AttestEvidenceRecorder implements AttestsIssuance, DurableEvidenceRecorder, EvidenceRecorder
{
    public function __construct(
        private readonly AttestRegistry $attest,
        private readonly EvidenceRecorder $fallback,
        private readonly ConnectionInterface $connection,
        private readonly Dispatcher $events,
        private readonly Closure $chainIdUsing,
        private readonly string $table = 'verdict_evidence',
        private readonly bool $chainProvenance = false,
        private readonly string $onFailure = 'alert',
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 50,
    ) {
        if (! in_array($this->onFailure, ['alert', 'throw'], true)) {
            throw new InvalidArgumentException("Unknown on_failure mode [{$this->onFailure}]. Expected 'alert' or 'throw'.");
        }

        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException("The maximum attempts must be at least 1, got [{$this->maxAttempts}].");
        }

        if ($this->baseDelayMs < 0) {
            throw new InvalidArgumentException("The base delay in milliseconds must not be negative, got [{$this->baseDelayMs}].");
        }
    }

    public function record(DecisionEvidence $evidence): void
    {
        $this->writeChained(
            correlationId: $evidence->envelopeId,
            recordType: 'decision',
            type: 'verdict.decision',
            payload: [
                'capability' => $evidence->capability,
                'tool_kind' => $evidence->toolKind,
                'configuration_fingerprint' => $evidence->configurationFingerprint,
                'actor_fingerprint' => $evidence->actorFingerprint,
                'subject_fingerprint' => $evidence->subjectFingerprint,
                'target_source' => $evidence->targetSource,
                'tool_description_fingerprint' => $evidence->toolDescriptionFingerprint,
                'invocation_tool_description_fingerprint' => $evidence->invocationToolDescriptionFingerprint,
                'tool_description_matched' => $evidence->toolDescriptionMatched,
                'stage' => $evidence->stage,
                'disposition' => $evidence->disposition,
                // Verdict's own identity for this record travels inside the payload Attest signs.
                // Attest cannot sign this value directly — it hashes its own envelope over its own
                // RFC 8785 encoder — so covering it is what makes Attest *protect* the identity
                // rather than define it. See RecordDigest.
                'claim_type' => $evidence->claimType?->value,
                'record_digest' => $evidence->recordDigest,
                'reason' => $evidence->reason,
                'argument_fingerprint' => $evidence->argumentFingerprint,
                'idempotency_key_fingerprint' => $evidence->idempotencyKey === null ? null : hash('sha256', $evidence->idempotencyKey),
                'approval_receipt_fingerprint' => $evidence->approvalReceiptFingerprint,
                'review_request_fingerprint' => $evidence->reviewRequestFingerprint,
                'review_outcome' => $evidence->reviewOutcome,
                'approval_phase' => $evidence->approvalPhase,
                'approval_outcome' => $evidence->approvalOutcome,
                'target_policy' => $evidence->targetPolicy,
                'target_strategy' => $evidence->targetStrategy,
                'proposal_target_identity_fingerprint' => $evidence->proposalTargetIdentityFingerprint,
                'execution_target_identity_fingerprint' => $evidence->executionTargetIdentityFingerprint,
                'target_identity_matched' => $evidence->targetIdentityMatched,
                'rate_limit_key_fingerprint' => $evidence->rateLimitKeyFingerprint,
                'rate_limit_policy' => $evidence->rateLimitPolicy,
                'rate_limit_limit' => $evidence->rateLimitLimit,
                'rate_limit_remaining' => $evidence->rateLimitRemaining,
                'rate_limit_reset_at' => $evidence->rateLimitResetAt?->format(DATE_ATOM),
                'execution_claim_fingerprint' => $evidence->executionClaimFingerprint,
                'execution_claim_binding_fingerprint' => $evidence->executionClaimBindingFingerprint,
                'execution_claim_policy' => $evidence->executionClaimPolicy,
                'execution_claim_status' => $evidence->executionClaimStatus,
                'execution_claim_attempt' => $evidence->executionClaimAttempt,
                'invocation_id' => $evidence->invocationId,
                'intent_id' => $evidence->intentId,
                'recorded_at' => $evidence->recordedAt->format(DATE_ATOM),
            ],
        );
    }

    public function recordRelease(ContextReleaseEvidence $evidence): void
    {
        $this->writeChained(
            correlationId: $evidence->invocationId,
            recordType: 'context_release',
            type: 'verdict.context_release',
            payload: [
                'source' => $evidence->source,
                'destination' => $evidence->destination,
                'trust_zone' => $evidence->trustZone,
                'trust' => $evidence->trust->value,
                'data_class' => $evidence->dataClass->value,
                'disposition' => $evidence->disposition,
                'reason' => $evidence->reason,
                'requested_path_fingerprints' => $evidence->requestedPathFingerprints,
                'released_path_fingerprints' => $evidence->releasedPathFingerprints,
                'transform_fingerprints' => $evidence->transformFingerprints,
                'transformed_path_fingerprints' => $evidence->transformedPathFingerprints,
                'payload_fingerprint' => $evidence->payloadFingerprint,
                'invocation_id' => $evidence->invocationId,
                'configuration_fingerprint' => $evidence->configurationFingerprint,
                'recorded_at' => $evidence->recordedAt->format(DATE_ATOM),
            ],
        );
    }

    public function recordProvenance(ProvenanceEntry $entry): void
    {
        $this->fallback->recordProvenance($entry);

        if (! $this->chainProvenance) {
            return;
        }

        $this->writeChained(
            correlationId: $entry->correlationId,
            recordType: 'provenance',
            type: 'verdict.provenance',
            payload: [
                'source' => $entry->source->identity(),
                'trust' => $entry->trust->value,
                'data_class' => $entry->dataClass->value,
                'channel' => $entry->channel->value,
                'component_label' => $entry->componentLabel,
                'component_fingerprint' => $entry->componentFingerprint,
                'content_fingerprint' => $entry->contentFingerprint,
                'recorded_at' => $entry->recordedAt->format(DATE_ATOM),
            ],
        );
    }

    public function recordDerivation(ProvenanceDerivation $derivation): void
    {
        $this->fallback->recordDerivation($derivation);

        if (! $this->chainProvenance) {
            return;
        }

        $this->writeChained(
            correlationId: $derivation->correlationId,
            recordType: 'provenance_derivation',
            type: 'verdict.provenance_derivation',
            payload: [
                'child_content_fingerprint' => $derivation->childContentFingerprint,
                'parent_content_fingerprint' => $derivation->parentContentFingerprint,
                'kind' => $derivation->kind->value,
                'recorded_at' => $derivation->recordedAt->format(DATE_ATOM),
            ],
        );
    }

    public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void
    {
        $this->writeChained(
            correlationId: $evidence->identityFingerprint,
            recordType: 'approval_operation',
            type: 'verdict.approval_operation',
            payload: $evidence->toArray(),
        );
    }

    public function attestIssuedSummary(ApprovalLane $lane, string $identityFingerprint, ApproverSummary $summary): void
    {
        $attempt = 0;
        $lastError = null;

        try {
            $chainId = ($this->chainIdUsing)();
        } catch (Throwable $e) {
            throw EvidenceChainWriteFailed::fromFailure('unknown', 'attested_issuance', $attempt, $e);
        }

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            try {
                $this->attest->chain($chainId)->record(
                    type: 'verdict.attested_issuance',
                    payload: [
                        'lane' => $lane->value,
                        'identity_fingerprint' => $identityFingerprint,
                        'summary_fingerprint' => $summary->fingerprint,
                    ],
                    correlation: $identityFingerprint,
                );

                return;
            } catch (Throwable $e) {
                $lastError = $e;

                if ($attempt < $this->maxAttempts) {
                    usleep($this->baseDelayMs * 1000 * (2 ** ($attempt - 1)));
                }
            }
        }

        throw EvidenceChainWriteFailed::fromFailure($chainId, 'attested_issuance', $attempt, $lastError);
    }

    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array
    {
        return $this->fallback->provenanceFor($correlationId);
    }

    /** @return list<ProvenanceDerivation> */
    public function derivationsFor(string $correlationId, string $childContentFingerprint): array
    {
        return $this->fallback->derivationsFor($correlationId, $childContentFingerprint);
    }

    /** @param array<string, mixed> $payload */
    private function writeChained(?string $correlationId, string $recordType, string $type, array $payload): void
    {
        $attempt = 0;
        $lastError = null;
        $resolverFailed = false;

        try {
            $chainId = ($this->chainIdUsing)();
        } catch (Throwable $e) {
            // Resolving the chain id itself failed. Treat this as an immediate
            // exhaustion — there is nothing to retry — and fall straight through
            // to gap-handling below with a placeholder chain id.
            $chainId = 'unknown';
            $lastError = $e;
            $resolverFailed = true;
        }

        while (! $resolverFailed && $attempt < $this->maxAttempts) {
            $attempt++;

            try {
                $this->attest->chain($chainId)->record(
                    type: $type,
                    payload: $payload,
                    correlation: $correlationId,
                );

                return;
            } catch (Throwable $e) {
                $lastError = $e;

                if ($attempt < $this->maxAttempts) {
                    usleep($this->baseDelayMs * 1000 * (2 ** ($attempt - 1)));
                }
            }
        }

        $phase = $resolverFailed ? 'resolve_chain_id' : 'append';

        $this->recordGap($chainId, $correlationId, $recordType, $phase, $attempt, $lastError);

        try {
            $this->events->dispatch(new ChainWriteFailed(
                chainId: $chainId,
                correlationId: $correlationId,
                recordType: $recordType,
                phase: $phase,
                attempts: $attempt,
                message: $lastError?->getMessage() ?? 'unknown error',
            ));
        } catch (Throwable) {
            // An alert listener failing must not block the caller either.
        }

        if ($this->onFailure === 'throw') {
            throw EvidenceChainWriteFailed::fromFailure($chainId, $recordType, $attempt, $lastError);
        }
    }

    private function recordGap(string $chainId, ?string $correlationId, string $recordType, string $phase, int $attempts, ?Throwable $error): void
    {
        try {
            $this->connection->table($this->table)->insert([
                'id' => Str::uuid()->toString(),
                'record_type' => 'chain_gap',
                'correlation_id' => $correlationId,
                'stage' => $recordType,
                'disposition' => 'gap',
                'reason' => json_encode([
                    'chain' => $chainId,
                    'phase' => $phase,
                    'attempts' => $attempts,
                    'error' => $error?->getMessage(),
                ], JSON_THROW_ON_ERROR),
                // This timezone-naive column is formatted in the object's zone by Laravel bindings, so mint UTC.
                'recorded_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ]);
        } catch (Throwable) {
            // Best-effort fallback; do not let a broken fallback path itself block the caller.
        }
    }
}
