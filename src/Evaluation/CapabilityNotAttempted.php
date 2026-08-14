<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use RuntimeException;

/**
 * The capability an attack case exists to test never appeared in the observation.
 *
 * This is an absence of evidence, not a security finding. Under a deterministic runner it cannot
 * happen — the runner always drives the attacked capability. Under a live agent it is common: a
 * model that reaches for a different tool, declines part-way, or satisfies the request with a read
 * instead of a mutation produces an observation with no entry for the attacked capability.
 *
 * Reporting that as a failed security case would invert its meaning. A reader scanning results sees
 * a failed cross-principal case and reasonably infers a boundary problem, when in fact nothing
 * attacked the boundary. `SecuritySuite` catches this like any other taxonomy exception, so the
 * trial is recorded as an error and excluded from the pass rate — the same treatment
 * {@see ModelDeclinedToAct}, {@see CaseNotLiveExpressible}, and {@see LiveObservationUnavailable}
 * already receive.
 *
 * A capability that *executed* is a different matter entirely and remains an assertion failure.
 *
 * See [#139](https://github.com/fissible/verdict/issues/139).
 */
final class CapabilityNotAttempted extends RuntimeException
{
    public static function forCapability(string $capability): self
    {
        return new self(
            "The observation contains no call to [{$capability}], so this case measured nothing ".
            'about it. The attacked capability was never attempted, which is neither a pass nor a '.
            'failure.'
        );
    }
}
