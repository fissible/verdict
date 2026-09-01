<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Support\ApproverSummary;

interface AttestsIssuance
{
    /**
     * Synchronously anchors the released summary that authorizes an issuance.
     *
     * @throws \Throwable When the attestation cannot be appended.
     */
    public function attestIssuedSummary(ApprovalLane $lane, string $identityFingerprint, ApproverSummary $summary): void;
}
