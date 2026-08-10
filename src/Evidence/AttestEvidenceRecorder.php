<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Closure;
use DateTimeImmutable;
use Fissible\AttestLaravel\Support\AttestRegistry;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\Events\ChainWriteFailed;
use Fissible\Verdict\Exceptions\EvidenceChainWriteFailed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class AttestEvidenceRecorder implements EvidenceRecorder
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
    }

    public function record(DecisionEvidence $evidence): void
    {
        $this->writeChained(
            correlationId: $evidence->envelopeId,
            recordType: 'decision',
            type: 'verdict.decision',
            payload: [
                'capability' => $evidence->capability,
                'stage' => $evidence->stage,
                'disposition' => $evidence->disposition,
                'reason' => $evidence->reason,
                'argument_fingerprint' => $evidence->argumentFingerprint,
                'idempotency_key_fingerprint' => $evidence->idempotencyKey === null ? null : hash('sha256', $evidence->idempotencyKey),
                'approval_receipt_fingerprint' => $evidence->approvalReceiptFingerprint,
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
                'recorded_at' => $evidence->recordedAt->format(DATE_ATOM),
            ],
        );
    }

    public function recordProvenance(ProvenanceEntry $entry): void
    {
        throw new LogicException('Not yet implemented — see Task 5.');
    }

    public function recordDerivation(ProvenanceDerivation $derivation): void
    {
        throw new LogicException('Not yet implemented — see Task 5.');
    }

    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array
    {
        throw new LogicException('Not yet implemented — see Task 5.');
    }

    /** @return list<ProvenanceDerivation> */
    public function derivationsFor(string $correlationId, string $childContentFingerprint): array
    {
        throw new LogicException('Not yet implemented — see Task 5.');
    }

    /** @param array<string, mixed> $payload */
    private function writeChained(?string $correlationId, string $recordType, string $type, array $payload): void
    {
        $chainId = ($this->chainIdUsing)();
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxAttempts) {
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

        $this->recordGap($chainId, $correlationId, $recordType, $attempt, $lastError);

        $this->events->dispatch(new ChainWriteFailed(
            chainId: $chainId,
            correlationId: $correlationId,
            recordType: $recordType,
            attempts: $attempt,
            message: $lastError?->getMessage() ?? 'unknown error',
        ));

        if ($this->onFailure === 'throw') {
            throw EvidenceChainWriteFailed::fromFailure($chainId, $recordType, $attempt, $lastError);
        }
    }

    private function recordGap(string $chainId, ?string $correlationId, string $recordType, int $attempts, ?Throwable $error): void
    {
        $this->connection->table($this->table)->insert([
            'id' => Str::uuid()->toString(),
            'record_type' => 'chain_gap',
            'correlation_id' => $correlationId,
            'stage' => $recordType,
            'disposition' => 'gap',
            'reason' => json_encode([
                'chain' => $chainId,
                'attempts' => $attempts,
                'error' => $error?->getMessage(),
            ], JSON_THROW_ON_ERROR),
            'recorded_at' => new DateTimeImmutable,
        ]);
    }
}
