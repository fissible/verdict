<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;

final readonly class DatabaseEvidenceRecorder implements EvidenceRecorder
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_evidence',
        private string $derivationsTable = 'verdict_provenance_derivations',
    ) {}

    public function record(DecisionEvidence $evidence): void
    {
        $this->connection->table($this->table)->insert([
            'id' => Str::uuid()->toString(),
            'record_type' => 'decision',
            'correlation_id' => $evidence->envelopeId,
            'invocation_id' => $evidence->invocationId,
            'capability' => $evidence->capability,
            'tool_kind' => $evidence->toolKind,
            'configuration_fingerprint' => $evidence->configurationFingerprint,
            'actor_fingerprint' => $evidence->actorFingerprint,
            'subject_fingerprint' => $evidence->subjectFingerprint,
            'target_source' => $evidence->targetSource,
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
            'invocation_id' => $evidence->invocationId,
            'capability' => null,
            'tool_kind' => null,
            'configuration_fingerprint' => $evidence->configurationFingerprint,
            'actor_fingerprint' => null,
            'subject_fingerprint' => null,
            'target_source' => null,
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

    public function recordProvenance(ProvenanceEntry $entry): void
    {
        $this->connection->table($this->table)->insert([
            'id' => Str::uuid()->toString(),
            'record_type' => 'provenance',
            'correlation_id' => $entry->correlationId,
            'invocation_id' => $entry->correlationId,
            'stage' => 'input',
            'disposition' => 'recorded',
            'source' => $entry->source->identity(),
            'trust' => $entry->trust->value,
            'data_class' => $entry->dataClass->value,
            'channel' => $entry->channel->value,
            'component_label' => $entry->componentLabel,
            'component_fingerprint' => $entry->componentFingerprint,
            'content_fingerprint' => $entry->contentFingerprint,
            'recorded_at' => $entry->recordedAt,
        ]);
    }

    public function recordDerivation(ProvenanceDerivation $derivation): void
    {
        $this->connection->table($this->derivationsTable)->insert([
            'correlation_id' => $derivation->correlationId,
            'child_content_fingerprint' => $derivation->childContentFingerprint,
            'parent_content_fingerprint' => $derivation->parentContentFingerprint,
            'kind' => $derivation->kind->value,
            'recorded_at' => $derivation->recordedAt,
        ]);
    }

    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array
    {
        $rows = $this->connection->table($this->table)
            ->where('record_type', 'provenance')
            ->where('correlation_id', $correlationId)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();
        $entries = [];

        foreach ($rows as $row) {
            $entries[] = new ProvenanceEntry(
                correlationId: (string) $row->correlation_id,
                source: $this->source((string) $row->source),
                trust: Trust::from((string) $row->trust),
                dataClass: DataClass::from((string) $row->data_class),
                channel: ContextChannel::from((string) $row->channel),
                contentFingerprint: (string) $row->content_fingerprint,
                componentLabel: $row->component_label === null ? null : (string) $row->component_label,
                componentFingerprint: $row->component_fingerprint === null ? null : (string) $row->component_fingerprint,
                recordedAt: new DateTimeImmutable((string) $row->recorded_at, new \DateTimeZone('UTC')),
            );
        }

        return $entries;
    }

    /** @return list<ProvenanceDerivation> */
    public function derivationsFor(string $correlationId, string $childContentFingerprint): array
    {
        $rows = $this->connection->table($this->derivationsTable)
            ->where('correlation_id', $correlationId)
            ->where('child_content_fingerprint', $childContentFingerprint)
            ->orderBy('recorded_at')
            ->get();
        $derivations = [];

        foreach ($rows as $row) {
            $derivations[] = new ProvenanceDerivation(
                correlationId: (string) $row->correlation_id,
                childContentFingerprint: (string) $row->child_content_fingerprint,
                parentContentFingerprint: (string) $row->parent_content_fingerprint,
                kind: DerivationKind::from((string) $row->kind),
                recordedAt: new DateTimeImmutable((string) $row->recorded_at, new \DateTimeZone('UTC')),
            );
        }

        return $derivations;
    }

    private function optionalFingerprint(?string $value): ?string
    {
        return $value === null ? null : hash('sha256', $value);
    }

    private function source(string $identity): Source
    {
        [$kind, $name] = array_pad(explode(':', $identity, 2), 2, '');

        return match ($kind) {
            'application' => Source::application($name),
            'user' => Source::user($name),
            'external' => Source::external($name),
            default => throw new LogicException("Unknown provenance source kind [{$kind}]."),
        };
    }
}
