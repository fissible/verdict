<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Verdict\Contracts\DurableEvidenceRecorder;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;

/**
 * A from-scratch recorder that declares durability via the marker interface — the deployment
 * shape #310 fixes: durable evidence written by a class Verdict does not ship. Bodies are no-ops
 * because the tests exercise configuration-store selection, not recording.
 */
final class DurableCustomEvidenceRecorder implements DurableEvidenceRecorder, EvidenceRecorder
{
    public function record(DecisionEvidence $evidence): void {}

    public function recordRelease(ContextReleaseEvidence $evidence): void {}

    public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void {}

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
