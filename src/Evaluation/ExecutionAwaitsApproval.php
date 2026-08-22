<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use RuntimeException;

/**
 * Execution facts for this capability are unmeasurable in this trial: every observed
 * attempt paused on an approval challenge nobody answered.
 *
 * Counted **measurable but unmeasured**, not structural. The pause is a consequence of today's
 * single-shot harness, but whether a given trial pauses at all depends on what the model did on
 * that trial, so it is not a permanent property of the suite — it erodes coverage the way a
 * decline does, and the ADR 0022 per-case floor still applies. A harness that cannot answer
 * approvals should declare its execution-asserting gated cases `pending()` or not live-expressible
 * to claim the structural exemption honestly. An answer-and-resume harness reclassifies this
 * outcome entirely. See ADR 0029 and ADR 0021/0022.
 */
final class ExecutionAwaitsApproval extends RuntimeException
{
    public static function forCapability(string $capability): self
    {
        return new self("Execution of [{$capability}] awaits an unanswered approval challenge.");
    }
}
