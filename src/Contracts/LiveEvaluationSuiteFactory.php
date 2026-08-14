<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evaluation\SecuritySuite;

/**
 * Creates a configured SecuritySuite for live evaluation.
 *
 * `make()` is called **once per trial**, not once per run. Implementing only this interface
 * therefore limits a run to a single trial: a multi-trial run is refused before any model is
 * invoked, because rebuilding a suite does not reset the state a previous trial created, and a
 * pass rate computed over contaminated trials reports fixture residue as model behaviour.
 *
 * To run more than one trial, implement {@see LiveEvaluationTrialFactory} instead, whose single
 * operation resets application-owned state and then builds that trial's suite.
 *
 * See [ADR 0020](../../docs/adr/0020-live-trial-isolation-is-application-owned.md) and
 * [#137](https://github.com/fissible/verdict/issues/137).
 */
interface LiveEvaluationSuiteFactory
{
    public function make(): SecuritySuite;
}
