<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Contracts\EvidenceRecorder;

/**
 * Test and local-development recorder with unbounded process-local storage.
 *
 * Do not use this recorder in production, Octane, queue workers, or any other
 * long-running process where records could accumulate or cross request boundaries.
 */
final class InMemoryEvidenceRecorder implements EvidenceRecorder
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

    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array
    {
        return array_values(array_filter(
            $this->provenanceRecords,
            fn (ProvenanceEntry $entry): bool => $entry->correlationId === $correlationId,
        ));
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
            return [$left->recordedAt, $left->parentContentFingerprint, $left->kind->value]
                <=> [$right->recordedAt, $right->parentContentFingerprint, $right->kind->value];
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
}
