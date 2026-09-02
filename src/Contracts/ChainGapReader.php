<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evidence\ChainGapSummary;

/**
 * The chain-gap read contract: a narrow, observational, container-resolved read seam over
 * persisted chain_gap marks, separate from the attested evidence write path and without
 * exposing its evidence-table schema to consumers.
 *
 * The persisted count is a floor, never a total: AttestEvidenceRecorder::recordGap() is
 * best-effort and swallows an insert failure, so no persisted marks is not proof that a chain
 * has had no gaps.
 */
interface ChainGapReader
{
    /**
     * The persisted chain_gap marks for this exact chain identity.
     */
    public function gapsForChain(string $chainId): ChainGapSummary;
}
