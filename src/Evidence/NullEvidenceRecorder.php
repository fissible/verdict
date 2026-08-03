<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Contracts\EvidenceRecorder;

final class NullEvidenceRecorder implements EvidenceRecorder
{
    public function record(DecisionEvidence $evidence): void
    {
        // Applications opt in to an evidence destination explicitly.
    }

    public function recordRelease(ContextReleaseEvidence $evidence): void
    {
        // Applications opt in to an evidence destination explicitly.
    }

    public function recordProvenance(ProvenanceEntry $entry): void
    {
        // Applications opt in to an evidence destination explicitly.
    }

    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array
    {
        return [];
    }
}
