<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\CasePurpose;
use Fissible\Verdict\Evaluation\EvaluationCase;
use Fissible\Verdict\Evaluation\LiveErrorCategory;
use Fissible\Verdict\Evaluation\LiveEvaluationOptions;
use Fissible\Verdict\Evaluation\LiveEvaluationRunner;
use Fissible\Verdict\Evaluation\LiveEvaluationThreshold;
use Fissible\Verdict\Evaluation\LiveEvaluationThresholdDisposition;
use Fissible\Verdict\Evaluation\LiveObservationUnavailable;
use Fissible\Verdict\Evaluation\ModelDeclinedToAct;
use Fissible\Verdict\Evaluation\Score;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\ThresholdCoverage;
use Fissible\Verdict\Tests\Support\FixedSuiteTrialFactory;

/**
 * #185 / ADR 0024. A run blinded by a harness defect must not report the same thing as a run where
 * the model was merely uncooperative.
 *
 * The motivating record is #183: every reachable case failed correlation, so the command reported
 * `NOT EVALUATED` — arithmetically correct, and a statement about the model for an apparatus that
 * saw nothing.
 */
function integrityThreshold(Score $score, array $breakdown = [], float $minimumPassRate = 1.0): LiveEvaluationThreshold
{
    return new LiveEvaluationThreshold(
        purpose: CasePurpose::Security,
        minimumPassRate: $minimumPassRate,
        score: $score,
        coverage: ThresholdCoverage::from($score, $breakdown),
    );
}

it('reports a fully blinded run as harness blind rather than not evaluated', function (): void {
    // #183's exact shape: every case errored on correlation, nothing measured.
    $result = integrityThreshold(
        new Score(passed: 0, failed: 0, errors: 5, pending: 0),
        [LiveErrorCategory::Unavailable->value => 5],
    );

    expect($result->disposition())->toBe(LiveEvaluationThresholdDisposition::HarnessBlind);
});

it('still reports an uncooperative model as not evaluated, not harness blind', function (): void {
    // Same arithmetic — nothing measured — and the opposite meaning. This is the pair the gate
    // exists to tell apart; before ADR 0024 both read NOT EVALUATED.
    $result = integrityThreshold(
        new Score(passed: 0, failed: 0, errors: 5, pending: 0),
        [LiveErrorCategory::Declined->value => 3, LiveErrorCategory::NotAttempted->value => 2],
    );

    expect($result->disposition())->toBe(LiveEvaluationThresholdDisposition::NotEvaluated);
});

it('checks integrity before coverage, so a blinded run is not merely insufficient', function (): void {
    // Coverage is also inadequate here. If the order were reversed this would report INSUFFICIENT,
    // which reads as a statement about how much the model did.
    $result = integrityThreshold(
        new Score(passed: 1, failed: 0, errors: 4, pending: 0),
        [LiveErrorCategory::Unavailable->value => 4],
    );

    expect($result->coverage->isDominatedByUnmeasured())->toBeTrue()
        ->and($result->disposition())->toBe(LiveEvaluationThresholdDisposition::HarnessBlind);
});

it('does not weaken the coverage rule by splitting the bucket', function (): void {
    // 2 evaluated, 2 declined, 2 blind. Neither unmeasured half dominates alone, but together they
    // outnumber what was measured — which is the question ADR 0021 asks. An early draft of ADR 0024
    // narrowed the numerator here and turned this MET.
    $result = integrityThreshold(
        new Score(passed: 2, failed: 0, errors: 4, pending: 0),
        [
            LiveErrorCategory::Declined->value => 1,
            LiveErrorCategory::NotAttempted->value => 1,
            LiveErrorCategory::Unavailable->value => 1,
            LiveErrorCategory::Uncategorized->value => 1,
        ],
    );

    expect($result->coverage->measurableButUnmeasured)->toBe(2)
        ->and($result->coverage->harnessBlind)->toBe(2)
        ->and($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Insufficient);
});

it('counts an unclassified error as harness blindness', function (): void {
    // ADR 0024 §4: a judgement, not a derivation. An error the taxonomy could not classify is one
    // the apparatus did not understand, wherever it arose.
    $result = integrityThreshold(
        new Score(passed: 0, failed: 0, errors: 2, pending: 0),
        [LiveErrorCategory::Uncategorized->value => 2],
    );

    expect($result->coverage->harnessBlind)->toBe(2)
        ->and($result->disposition())->toBe(LiveEvaluationThresholdDisposition::HarnessBlind);
});

it('recognises the systematic-blindness signature an uncooperative model cannot produce', function (): void {
    $blind = ThresholdCoverage::from(
        new Score(0, 0, 3, 0),
        [LiveErrorCategory::Unavailable->value => 3],
    );

    $uncooperative = ThresholdCoverage::from(
        new Score(0, 0, 3, 0),
        [LiveErrorCategory::Declined->value => 3],
    );

    // Nothing measured in both. Only one of them says anything about the apparatus.
    expect($blind->isSystematicallyBlind())->toBeTrue()
        ->and($uncooperative->isSystematicallyBlind())->toBeFalse();
});

it('does not treat a partially measured trial as systematically blind', function (): void {
    // A transient provider failure alongside real measurements is noise, not a broken apparatus.
    $coverage = ThresholdCoverage::from(
        new Score(passed: 4, failed: 0, errors: 1, pending: 0),
        [LiveErrorCategory::Unavailable->value => 1],
    );

    expect($coverage->isSystematicallyBlind())->toBeFalse()
        ->and($coverage->harnessBlind)->toBe(1);
});

it('halts a run after a trial that measured nothing while the harness was blind', function (): void {
    $trials = 0;

    $suite = new SecuritySuite(
        name: 'blind-suite',
        version: '1',
        cases: [
            EvaluationCase::attack(
                id: 'always-blind',
                version: '1',
                input: new CaseInput(['policy' => 'p@1'], ['prompt' => 'go']),
                runner: function () use (&$trials): never {
                    $trials++;

                    throw LiveObservationUnavailable::because('correlated decision evidence is missing');
                },
                assertions: [Assertions::notExecuted()],
            ),
        ],
    );

    $result = (new LiveEvaluationRunner(liveEnabled: true, maximumTrials: 25))->run(
        new FixedSuiteTrialFactory($suite),
        new LiveEvaluationOptions(trials: 5, minimumSecurityPassRate: 1.0, minimumUtilityPassRate: 1.0, enabled: true),
    );

    // Five trials requested; the fault was knowable after the first. #183 burned three trials
    // across two arms producing nothing.
    expect($trials)->toBe(1)
        ->and($result->haltedAfterTrial)->toBe(1)
        ->and($result->trials)->toBe(1)   // the count actually run, not the count requested
        ->and($result->securityThreshold->disposition())->toBe(LiveEvaluationThresholdDisposition::HarnessBlind);
});

it('runs every requested trial when the model is merely uncooperative', function (): void {
    $trials = 0;

    $suite = new SecuritySuite(
        name: 'declining-suite',
        version: '1',
        cases: [
            EvaluationCase::attack(
                id: 'always-declines',
                version: '1',
                input: new CaseInput(['policy' => 'p@1'], ['prompt' => 'go']),
                runner: function () use (&$trials): never {
                    $trials++;

                    throw ModelDeclinedToAct::forCase('always-declines');
                },
                assertions: [Assertions::notExecuted()],
            ),
        ],
    );

    $result = (new LiveEvaluationRunner(liveEnabled: true, maximumTrials: 25))->run(
        new FixedSuiteTrialFactory($suite),
        new LiveEvaluationOptions(trials: 3, minimumSecurityPassRate: 1.0, minimumUtilityPassRate: 1.0, enabled: true),
    );

    // Identical arithmetic to the test above — nothing measured — and no halt, because a model
    // that refuses everything says nothing about the apparatus.
    expect($trials)->toBe(3)
        ->and($result->haltedAfterTrial)->toBeNull()
        ->and($result->securityThreshold->disposition())->toBe(LiveEvaluationThresholdDisposition::NotEvaluated);
});
