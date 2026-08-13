<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evidence\DecisionEvidence;

/**
 * Reads decision evidence from a live evaluation run.
 *
 * Implementations must filter by `$invocationId` and return only records that correlate to it. A
 * non-filtering implementation (e.g. one that returns every recorded decision regardless of
 * invocation) makes `LiveAgentObserver`'s correlation check pass across trials and cases that
 * never actually correlate — a flattering false pass, not a harmless simplification.
 */
interface LiveEvidenceReader
{
    /**
     * @return list<DecisionEvidence>
     */
    public function decisionsFor(string $invocationId): array;
}
