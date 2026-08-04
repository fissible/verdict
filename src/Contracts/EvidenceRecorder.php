<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceEntry;

interface EvidenceRecorder
{
    public function record(DecisionEvidence $evidence): void;

    public function recordRelease(ContextReleaseEvidence $evidence): void;

    public function recordProvenance(ProvenanceEntry $entry): void;

    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array;
}
