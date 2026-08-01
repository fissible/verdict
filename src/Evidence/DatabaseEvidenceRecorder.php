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
            'requested_path_fingerprints' => null,
            'released_path_fingerprints' => null,
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
            'requested_path_fingerprints' => json_encode(
                $evidence->requestedPathFingerprints,
                JSON_THROW_ON_ERROR,
            ),
            'released_path_fingerprints' => json_encode(
                $evidence->releasedPathFingerprints,
                JSON_THROW_ON_ERROR,
            ),
            'payload_fingerprint' => $evidence->payloadFingerprint,
            'recorded_at' => $evidence->recordedAt,
        ]);
    }

    private function optionalFingerprint(?string $value): ?string
    {
        return $value === null ? null : hash('sha256', $value);
    }
}
