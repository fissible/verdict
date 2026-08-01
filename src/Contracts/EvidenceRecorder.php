<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;

interface EvidenceRecorder
{
    public function record(DecisionEvidence $evidence): void;

    public function recordRelease(ContextReleaseEvidence $evidence): void;
}
