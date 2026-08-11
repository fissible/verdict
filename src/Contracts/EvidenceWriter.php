<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;

/** Writes Verdict evidence without requiring query capabilities. */
interface EvidenceWriter
{
    public function record(DecisionEvidence $evidence): void;

    public function recordRelease(ContextReleaseEvidence $evidence): void;

    public function recordProvenance(ProvenanceEntry $entry): void;

    public function recordDerivation(ProvenanceDerivation $derivation): void;
}
