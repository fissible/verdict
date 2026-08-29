<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

/**
 * @internal Excludes Verdict-owned evaluation-time reads from predicate observation by phase.
 *
 * The covered call sites are ResourceCheckpointCapture::capture() (checkpoint identity,
 * projection, and endpoint bookkeeping) and VerdictManager::targetIdentityFingerprint()
 * (execution-target refresh identity resolution). Executor statements remain observable.
 */
final class EvaluationReadPredicateSuppression
{
    private int $depth = 0;

    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    public function whileActive(callable $operation): mixed
    {
        $this->depth++;

        try {
            return $operation();
        } finally {
            $this->depth--;
        }
    }
}
