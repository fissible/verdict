<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evidence\DecisionEvidence;

/** Reads decision evidence from a live evaluation run. */
interface LiveEvidenceReader
{
    /**
     * @return list<DecisionEvidence>
     */
    public function decisionsFor(string $invocationId): array;
}
