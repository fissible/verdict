<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;

/**
 * A from-scratch recorder that does NOT declare durability: the no-op configuration store remains
 * the correct fall-through for it, and verdict:validate names the mismatch instead of Verdict
 * guessing. The twin of DurableCustomEvidenceRecorder, minus the marker.
 */
final class VolatileCustomEvidenceRecorder implements EvidenceRecorder
{
    public function record(DecisionEvidence $evidence): void {}

    public function recordRelease(ContextReleaseEvidence $evidence): void {}

    public function recordProvenance(ProvenanceEntry $entry): void {}

    public function recordDerivation(ProvenanceDerivation $derivation): void {}

    public function provenanceFor(string $correlationId): array
    {
        return [];
    }

    public function derivationsFor(string $correlationId, string $childContentFingerprint): array
    {
        return [];
    }
}
