<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;

/**
 * @experimental This mixed write and query contract will be split before Verdict 1.0; see #90.
 */
interface EvidenceRecorder
{
    public function record(DecisionEvidence $evidence): void;

    public function recordRelease(ContextReleaseEvidence $evidence): void;

    public function recordProvenance(ProvenanceEntry $entry): void;

    public function recordDerivation(ProvenanceDerivation $derivation): void;

    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array;

    /** @return list<ProvenanceDerivation> */
    public function derivationsFor(string $correlationId, string $childContentFingerprint): array;
}
