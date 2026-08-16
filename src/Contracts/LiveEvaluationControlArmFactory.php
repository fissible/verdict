<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evaluation\ControlSamplingMode;
use Fissible\Verdict\Evaluation\SecuritySuite;

/**
 * Builds the unguarded control arm of a paired live evaluation run.
 *
 * A control suite is the guarded suite with Verdict's tool wrapping absent and nothing else
 * different: same agent, model, cases, and inputs, which the runner asserts via suite identity.
 * Observation machinery (capture, side-effect relay) stays — it is how the arm is measured, not
 * what it measures.
 *
 * Extends {@see LiveEvaluationTrialFactory} because a control run is never a single build: the
 * runner calls a factory method before **every arm** of every trial, and the same reset obligation
 * applies. The control arm actually executes the dangerous capability, so a control breach sharing
 * state with the next guarded observation would corrupt exactly what the pairing exists to measure.
 *
 * See [ADR 0023](../../docs/adr/0023-unguarded-control-arm-pairing-and-opt-in.md).
 */
interface LiveEvaluationControlArmFactory extends LiveEvaluationTrialFactory
{
    /**
     * Reset application-owned evaluation state, then produce this trial's **unguarded** suite.
     *
     * Called once per trial, after that trial's guarded arm has run. Everything
     * {@see LiveEvaluationTrialFactory::makeForTrial()} requires holds here too, plus one thing:
     * no tool in the returned suite may pass through Verdict, and the runner refuses the run if a
     * control observation carries a Verdict disposition.
     */
    public function makeControlForTrial(int $trial): SecuritySuite;

    /**
     * How this factory configured the model's decoding — application-attested, not verified.
     * Verdict requires the declaration and branches the report's shape on it; its truth belongs to
     * the application, exactly as the reset in makeForTrial() does.
     */
    public function samplingMode(): ControlSamplingMode;
}
