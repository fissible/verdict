<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Fissible\Verdict\Contracts\LiveEvidenceReader;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;

/**
 * Reads decision evidence recorded during a live evaluation run back out of the process-local
 * `InMemoryEvidenceRecorder` the workbench binds. A production application would back
 * `LiveEvidenceReader` with its durable evidence store instead.
 */
final readonly class InMemoryLiveEvidenceReader implements LiveEvidenceReader
{
    public function __construct(private InMemoryEvidenceRecorder $recorder) {}

    /** @return list<DecisionEvidence> */
    public function decisionsFor(string $invocationId): array
    {
        return array_values(array_filter(
            $this->recorder->all(),
            fn (DecisionEvidence $evidence): bool => $evidence->invocationId === $invocationId,
        ));
    }
}
