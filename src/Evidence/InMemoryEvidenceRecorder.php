<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\RecordsApprovalRefusals;

/**
 * Test and local-development recorder with unbounded process-local storage.
 *
 * Do not use this recorder in production, Octane, queue workers, or any other
 * long-running process where records could accumulate or cross request boundaries.
 */
final class InMemoryEvidenceRecorder implements EvidenceRecorder, RecordsApprovalRefusals
{
    /**
     * @var list<DecisionEvidence>
     */
    private array $records = [];

    /** @var list<ContextReleaseEvidence> */
    private array $releaseRecords = [];

    /** @var list<ProvenanceEntry> */
    private array $provenanceRecords = [];

    /** @var list<ProvenanceDerivation> */
    private array $derivations = [];

    /** @var list<ApprovalOperationEvidence> */
    private array $operations = [];

    /**
     * Refusal-operation evidence, deduplicated per binding digest (the guard digest a refusal is
     * anchored on): the latest ApprovalRefusalEvidence for each digest, and how many times a refusal
     * for that binding was recorded. A replay flood grows the attempt count, not the row count.
     *
     * @var array<string, array{evidence: ApprovalRefusalEvidence, attempts: int}>
     */
    private array $refusals = [];

    public function record(DecisionEvidence $evidence): void
    {
        $this->records[] = $evidence;
    }

    public function recordRelease(ContextReleaseEvidence $evidence): void
    {
        $this->releaseRecords[] = $evidence;
    }

    public function recordProvenance(ProvenanceEntry $entry): void
    {
        $this->provenanceRecords[] = $entry;
    }

    public function recordDerivation(ProvenanceDerivation $derivation): void
    {
        foreach ($this->derivations as $recordedDerivation) {
            if ($recordedDerivation->correlationId === $derivation->correlationId
                && $recordedDerivation->childContentFingerprint === $derivation->childContentFingerprint
                && $recordedDerivation->parentContentFingerprint === $derivation->parentContentFingerprint
                && $recordedDerivation->kind === $derivation->kind) {
                return;
            }
        }

        $this->derivations[] = $derivation;
    }

    public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void
    {
        $this->operations[] = $evidence;
    }

    public function recordApprovalRefusal(ApprovalRefusalEvidence $evidence): void
    {
        $attempts = ($this->refusals[$evidence->bindingDigest]['attempts'] ?? 0) + 1;

        $this->refusals[$evidence->bindingDigest] = ['evidence' => $evidence, 'attempts' => $attempts];
    }

    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array
    {
        $entries = array_values(array_filter(
            $this->provenanceRecords,
            fn (ProvenanceEntry $entry): bool => $entry->correlationId === $correlationId,
        ));

        usort($entries, ProvenanceEntry::readOrder(...));

        return $entries;
    }

    /** @return list<ProvenanceDerivation> */
    public function derivationsFor(string $correlationId, string $childContentFingerprint): array
    {
        $derivations = array_values(array_filter(
            $this->derivations,
            fn (ProvenanceDerivation $derivation): bool => $derivation->correlationId === $correlationId
                && $derivation->childContentFingerprint === $childContentFingerprint,
        ));

        usort($derivations, static function (ProvenanceDerivation $left, ProvenanceDerivation $right): int {
            // Match the database write path's wall-clock precision without changing stored instants.
            return strcmp($left->recordedAt->format('Y-m-d H:i:s'), $right->recordedAt->format('Y-m-d H:i:s'))
                ?: strcmp($left->parentContentFingerprint, $right->parentContentFingerprint)
                ?: strcmp($left->kind->value, $right->kind->value);
        });

        return $derivations;
    }

    /**
     * @return list<DecisionEvidence>
     */
    public function all(): array
    {
        return $this->records;
    }

    public function latest(): ?DecisionEvidence
    {
        if ($this->records === []) {
            return null;
        }

        return $this->records[array_key_last($this->records)];
    }

    /** @return list<ContextReleaseEvidence> */
    public function releases(): array
    {
        return $this->releaseRecords;
    }

    /** @return list<ApprovalOperationEvidence> */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * The deduplicated latest refusal-operation evidence, one per binding digest (order-insensitive).
     *
     * @return list<ApprovalRefusalEvidence>
     */
    public function recordedRefusals(): array
    {
        return array_values(array_map(
            static fn (array $refusal): ApprovalRefusalEvidence => $refusal['evidence'],
            $this->refusals,
        ));
    }

    /** How many refusals have been recorded for the given binding digest (0 if none). */
    public function refusalAttempts(string $bindingDigest): int
    {
        return $this->refusals[$bindingDigest]['attempts'] ?? 0;
    }
}
