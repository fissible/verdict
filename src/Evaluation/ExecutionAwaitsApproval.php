<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use RuntimeException;

/**
 * Execution facts for this capability are unmeasurable in this trial: every observed
 * attempt paused on an approval challenge nobody answered. Structural for a single-shot
 * harness — an answer-and-resume harness reclassifies it. See ADR 0029 and ADR 0021/0022.
 */
final class ExecutionAwaitsApproval extends RuntimeException
{
    public static function forCapability(string $capability): self
    {
        return new self("Execution of [{$capability}] awaits an unanswered approval challenge.");
    }
}
