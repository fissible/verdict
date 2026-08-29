<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\DurableEvidenceRecorder;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;
use stdClass;

final readonly class DatabaseEvidenceRecorder implements DurableEvidenceRecorder, EvidenceRecorder
{
    /**
     * Mutable memo inside a readonly class. Evidence-table columns are inspected once per
     * recorder instance: after an additive migration, restart long-lived workers so a newly
     * constructed recorder observes the expanded schema.
     */
    private stdClass $schemaMemo;

    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_evidence',
        private string $derivationsTable = 'verdict_provenance_derivations',
    ) {
        $this->schemaMemo = new stdClass;
    }

    public function record(DecisionEvidence $evidence): void
    {
        $this->insert($this->table, [
            'id' => Str::uuid()->toString(),
            'record_type' => 'decision',
            'correlation_id' => $evidence->envelopeId,
            'invocation_id' => $evidence->invocationId,
            'intent_id' => $evidence->intentId,
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
            'claim_type' => $evidence->claimType?->value,
            'record_digest' => $evidence->recordDigest,
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
        $this->insert($this->table, [
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
            'tool_description_fingerprint' => null,
            'invocation_tool_description_fingerprint' => null,
            'tool_description_matched' => null,
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
        // A provenance record without its content fingerprint cannot be hydrated into evidence.
        // Do not leave an unreadable partial row behind on an install missing that migration.
        if (! in_array('content_fingerprint', $this->columns($this->table), true)) {
            return;
        }

        $this->insert($this->table, [
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
        $this->insert($this->derivationsTable, [
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

    public function hasTable(): bool
    {
        return $this->inspection($this->table)->tableExists;
    }

    public function table(): string
    {
        return $this->table;
    }

    /** @return list<string> */
    public function missingColumns(): array
    {
        return array_values(array_diff(self::evidenceColumns(), $this->columns($this->table)));
    }

    public function hasDerivationsTable(): bool
    {
        return $this->inspection($this->derivationsTable)->tableExists;
    }

    public function derivationsTable(): string
    {
        return $this->derivationsTable;
    }

    /**
     * Audited on the same terms as the evidence table (#363). The derivations table has no
     * additive migrations today, so this reports nothing until one lands — which is the point:
     * the first one must not reintroduce #356's failure on a table nothing checks.
     *
     * @return list<string>
     */
    public function missingDerivationsColumns(): array
    {
        return array_values(array_diff(
            self::derivationColumns(),
            $this->columns($this->derivationsTable),
        ));
    }

    /** @param array<string, mixed> $attributes */
    private function insert(string $table, array $attributes): void
    {
        $columns = array_flip($this->columns($table));
        $this->connection->table($table)->insert(array_intersect_key($attributes, $columns));
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $inspection = $this->inspection($table);

        if (! $inspection->tableExists) {
            throw new LogicException("The evidence table [{$table}] does not exist.");
        }

        /** @var list<string> */
        return $inspection->columns;
    }

    /**
     * Memoized per table, not per recorder: the evidence and derivations tables are inspected
     * independently, each once, and process restart remains the invalidation boundary for both.
     */
    private function inspection(string $table): stdClass
    {
        if (isset($this->schemaMemo->inspections[$table])) {
            return $this->schemaMemo->inspections[$table];
        }

        if (! $this->connection instanceof Connection) {
            throw new LogicException('The evidence connection does not support schema inspection.');
        }

        $schema = $this->connection->getSchemaBuilder();
        $inspection = new stdClass;
        $inspection->tableExists = $schema->hasTable($table);
        /** @var list<string> $columns */
        $columns = $inspection->tableExists ? $schema->getColumnListing($table) : [];
        $inspection->columns = $columns;

        $inspections = $this->schemaMemo->inspections ?? [];
        $inspections[$table] = $inspection;
        $this->schemaMemo->inspections = $inspections;

        return $inspection;
    }

    /** @return list<string> */
    private static function derivationColumns(): array
    {
        return [
            'correlation_id', 'child_content_fingerprint', 'parent_content_fingerprint', 'kind',
            'recorded_at',
        ];
    }

    /** @return list<string> */
    private static function evidenceColumns(): array
    {
        return [
            'id', 'record_type', 'correlation_id', 'invocation_id', 'intent_id', 'capability', 'tool_kind',
            'configuration_fingerprint', 'actor_fingerprint', 'subject_fingerprint', 'target_source',
            'tool_description_fingerprint', 'invocation_tool_description_fingerprint', 'tool_description_matched',
            'stage', 'disposition', 'claim_type', 'record_digest', 'reason', 'source', 'destination', 'trust_zone',
            'trust', 'data_class', 'channel', 'component_label', 'component_fingerprint', 'content_fingerprint',
            'argument_fingerprint', 'idempotency_key_fingerprint', 'approval_receipt_fingerprint', 'approval_phase',
            'approval_outcome', 'target_policy', 'target_strategy', 'proposal_target_identity_fingerprint',
            'execution_target_identity_fingerprint', 'target_identity_matched', 'rate_limit_key_fingerprint',
            'rate_limit_policy', 'rate_limit_limit', 'rate_limit_remaining', 'rate_limit_reset_at',
            'execution_claim_fingerprint', 'execution_claim_binding_fingerprint', 'execution_claim_policy',
            'execution_claim_status', 'execution_claim_attempt', 'requested_path_fingerprints',
            'released_path_fingerprints', 'transform_fingerprints', 'transformed_path_fingerprints',
            'transformation_count', 'payload_fingerprint', 'recorded_at',
        ];
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
