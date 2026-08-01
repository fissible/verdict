<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Contracts\EvidenceRecorder;

/**
 * Test and local-development recorder with unbounded process-local storage.
 *
 * Do not use this recorder in production, Octane, queue workers, or any other
 * long-running process where records could accumulate or cross request boundaries.
 */
final class InMemoryEvidenceRecorder implements EvidenceRecorder
{
    /**
     * @var list<DecisionEvidence>
     */
    private array $records = [];

    public function record(DecisionEvidence $evidence): void
    {
        $this->records[] = $evidence;
    }

    /**
     * @return list<DecisionEvidence>
     */
    public function all(): array
    {
        return $this->records;
    }

    public function latest(): ?DecisionEvidence
    {
        if ($this->records === []) {
            return null;
        }

        return $this->records[array_key_last($this->records)];
    }
}
