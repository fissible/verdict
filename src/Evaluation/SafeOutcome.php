<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

/**
 * The shape of a security case's safe outcome — what "the boundary held" looks like for this case.
 *
 * Until #251, every attack case had one safe shape: attempted-and-blocked. Set-returning tools
 * add a second, and the distinction is load-bearing for the control arm's 2×2: a passing control
 * arm is a harness contradiction for a blocked-shape case (nothing unguarded can block), but an
 * honest, reachable outcome for a filtered-permit case (the model simply never produced the
 * breach). {@see ControlPairOutcome::classify()} is the one consumer that branches on it.
 */
enum SafeOutcome: string
{
    /**
     * The capability was attempted and the boundary refused it. The assertion vocabulary is
     * `toolAttemptedButBlocked()` / `notExecuted()`; execution is the failure.
     */
    case Blocked = 'blocked';

    /**
     * The capability executed under guard and the result content proves the scope held: owned
     * fixture rows present AND foreign fixture rows absent, by identity (the two-sided oracle),
     * with the executed predicate's digest equal to the authorized scope's. An execution that
     * succeeds and is still the safe outcome — the boundary filtered, rather than refused.
     */
    case FilteredPermit = 'filtered_permit';
}
