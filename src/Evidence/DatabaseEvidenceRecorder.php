<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Contracts\EvidenceRecorder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class DatabaseEvidenceRecorder implements EvidenceRecorder
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_evidence',
    ) {}

    public function record(DecisionEvidence $evidence): void
    {
        $this->connection->table($this->table)->insert([
            'id' => Str::uuid()->toString(),
            'record_type' => 'decision',
            'correlation_id' => $evidence->envelopeId,
            'capability' => $evidence->capability,
            'stage' => $evidence->stage,
            'disposition' => $evidence->disposition,
            'reason' => $evidence->reason,
            'source' => null,
            'destination' => null,
            'trust_zone' => null,
            'trust' => null,
            'data_class' => null,
            'argument_fingerprint' => $evidence->argumentFingerprint,
            'idempotency_key_fingerprint' => $this->optionalFingerprint($evidence->idempotencyKey),
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
            'rate_limit_reset_at' => $evidence->rateLimitResetAt,
            'execution_claim_fingerprint' => $evidence->executionClaimFingerprint,
            'execution_claim_binding_fingerprint' => $evidence->executionClaimBindingFingerprint,
            'execution_claim_policy' => $evidence->executionClaimPolicy,
            'execution_claim_status' => $evidence->executionClaimStatus,
            'execution_claim_attempt' => $evidence->executionClaimAttempt,
            'requested_path_fingerprints' => null,
            'released_path_fingerprints' => null,
            'transform_fingerprints' => null,
            'transformed_path_fingerprints' => null,
            'transformation_count' => 0,
            'payload_fingerprint' => null,
            'recorded_at' => $evidence->recordedAt,
        ]);
    }

    public function recordRelease(ContextReleaseEvidence $evidence): void
    {
        $this->connection->table($this->table)->insert([
            'id' => Str::uuid()->toString(),
            'record_type' => 'context_release',
            'correlation_id' => null,
            'capability' => null,
            'stage' => 'release',
            'disposition' => $evidence->disposition,
            'reason' => $evidence->reason,
            'source' => $evidence->source,
            'destination' => $evidence->destination,
            'trust_zone' => $evidence->trustZone,
            'trust' => $evidence->trust->value,
            'data_class' => $evidence->dataClass->value,
            'argument_fingerprint' => null,
            'idempotency_key_fingerprint' => null,
            'approval_receipt_fingerprint' => null,
            'approval_phase' => null,
            'approval_outcome' => null,
            'target_policy' => null,
            'target_strategy' => null,
            'proposal_target_identity_fingerprint' => null,
            'execution_target_identity_fingerprint' => null,
            'target_identity_matched' => null,
            'rate_limit_key_fingerprint' => null,
            'rate_limit_policy' => null,
            'rate_limit_limit' => null,
            'rate_limit_remaining' => null,
            'rate_limit_reset_at' => null,
            'execution_claim_fingerprint' => null,
            'execution_claim_binding_fingerprint' => null,
            'execution_claim_policy' => null,
            'execution_claim_status' => null,
            'execution_claim_attempt' => null,
            'requested_path_fingerprints' => json_encode(
                $evidence->requestedPathFingerprints,
                JSON_THROW_ON_ERROR,
            ),
            'released_path_fingerprints' => json_encode(
                $evidence->releasedPathFingerprints,
                JSON_THROW_ON_ERROR,
            ),
            'transform_fingerprints' => json_encode(
                $evidence->transformFingerprints,
                JSON_THROW_ON_ERROR,
            ),
            'transformed_path_fingerprints' => json_encode(
                $evidence->transformedPathFingerprints,
                JSON_THROW_ON_ERROR,
            ),
            'transformation_count' => count($evidence->transformedPathFingerprints),
            'payload_fingerprint' => $evidence->payloadFingerprint,
            'recorded_at' => $evidence->recordedAt,
        ]);
    }

    private function optionalFingerprint(?string $value): ?string
    {
        return $value === null ? null : hash('sha256', $value);
    }
}
