<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evaluation\SecuritySuite;

/**
 * Creates a configured SecuritySuite for a single live evaluation trial, having first reset the
 * application-owned state that trial's measurement depends on.
 *
 * A multi-trial live evaluation requires this contract. Implementing only
 * {@see LiveEvaluationSuiteFactory} limits a run to one trial, which is refused before any model
 * invocation rather than producing a rate that assumes an independence it does not have.
 *
 * Reset and construction are deliberately one operation. A separate optional reset can be
 * half-implemented — a factory that rebuilds its suite but leaves its fixtures in place looks
 * compliant and reports the previous trial's side effects as this trial's model behaviour.
 *
 * See [ADR 0020](../../docs/adr/0020-live-trial-isolation-is-application-owned.md).
 */
interface LiveEvaluationTrialFactory extends LiveEvaluationSuiteFactory
{
    /**
     * Reset application-owned evaluation state, then produce this trial's suite.
     *
     * Called once before every trial, including the first — a process or database already used
     * before the run contaminates trial 0 exactly as it would trial 1.
     *
     * What resetting means is the application's to decide: truncating tables, rebinding fresh
     * in-memory stores, re-seeding fixtures, or rolling back a transaction are all valid. Verdict
     * cannot enumerate the state an application introduced outside it, and does not try.
     *
     * Every suite returned across a run must carry the same name, version, set of case identities,
     * and per-case immutable metadata. A difference is rejected as a configuration error rather
     * than reconciled. Case *order* may vary freely; results are aggregated by case identity.
     *
     * `$trial` is zero-based. An implementation may use it, but must not depend on it for
     * correctness — the protocol guarantees this runs before each trial, not that trials are
     * distinguishable.
     */
    public function makeForTrial(int $trial): SecuritySuite;
}
