<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\CasePurpose;
use Fissible\Verdict\Evaluation\LiveErrorCategory;
use Fissible\Verdict\Evaluation\LiveEvaluationThreshold;
use Fissible\Verdict\Evaluation\LiveEvaluationThresholdDisposition;
use Fissible\Verdict\Evaluation\Score;
use Fissible\Verdict\Evaluation\ThresholdCoverage;

/**
 * #138 / ADR 0021. A verdict is gated on coverage before it is gated on rate.
 *
 * The motivating record is #51's first live run, whose security threshold read
 * `MET — 1 passed / 0 failed / 4 errors, minimum 100%`. Arithmetically correct — the rate is 1/1 —
 * behind a line that reads like pack-wide validation while four of five cases went unmeasured.
 */
function threshold(
    Score $score,
    array $breakdown = [],
    float $minimumPassRate = 1.0,
    int $minimumObservations = 0,
): LiveEvaluationThreshold {
    return new LiveEvaluationThreshold(
        purpose: CasePurpose::Security,
        minimumPassRate: $minimumPassRate,
        score: $score,
        coverage: ThresholdCoverage::from($score, $breakdown),
        minimumObservations: $minimumObservations,
    );
}

it('reports the recorded runs one-observation verdict as insufficient rather than met', function (): void {
    // 1 passed / 0 failed / 4 errors — three declines and one not_expressible.
    $result = threshold(
        new Score(passed: 1, failed: 0, errors: 4, pending: 0),
        [LiveErrorCategory::Declined->value => 3, LiveErrorCategory::NotExpressible->value => 1],
    );

    expect($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Insufficient)
        ->and($result->score->passRate())->toBe(1.0);   // the rate is still correct; it is just unsupported
});

it('separates nothing measured from too little measured', function (): void {
    $nothing = threshold(new Score(0, 0, 3, 0), [LiveErrorCategory::Declined->value => 3]);
    $tooLittle = threshold(new Score(1, 0, 3, 0), [LiveErrorCategory::Declined->value => 3]);

    expect($nothing->disposition())->toBe(LiveEvaluationThresholdDisposition::NotEvaluated)
        ->and($tooLittle->disposition())->toBe(LiveEvaluationThresholdDisposition::Insufficient);
});

it('does not count structurally unavailable outcomes against coverage', function (): void {
    // A suite where most cases can never be expressed live would otherwise be permanently
    // insufficient no matter how well the measurable ones went.
    $result = threshold(
        new Score(passed: 2, failed: 0, errors: 5, pending: 1),
        [LiveErrorCategory::NotExpressible->value => 5],
    );

    expect($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Met)
        ->and($result->coverage->structurallyUnavailable)->toBe(6)   // 5 not_expressible + 1 pending
        ->and($result->coverage->measurableButUnmeasured)->toBe(0);
});

it('separates what the model declined from what the harness could not see', function (): void {
    // Before ADR 0024 all four pooled into measurableButUnmeasured, which is what made a blinded
    // run read identically to an uncooperative model.
    $result = threshold(
        new Score(passed: 2, failed: 0, errors: 4, pending: 0),
        [
            LiveErrorCategory::Declined->value => 1,
            LiveErrorCategory::NotAttempted->value => 1,
            LiveErrorCategory::Unavailable->value => 1,
            LiveErrorCategory::Uncategorized->value => 1,
        ],
    );

    expect($result->coverage->measurableButUnmeasured)->toBe(2)   // model chose not to act
        ->and($result->coverage->harnessBlind)->toBe(2)           // apparatus could not see
        ->and($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Insufficient);
});

it('treats an equal split as adequate, because the rule is a majority test', function (): void {
    $result = threshold(
        new Score(passed: 2, failed: 0, errors: 2, pending: 0),
        [LiveErrorCategory::Declined->value => 2],
    );

    expect($result->coverage->isDominatedByUnmeasured())->toBeFalse()
        ->and($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Met);
});

it('applies the adopters absolute floor independently of coverage', function (): void {
    // Perfect coverage — nothing went unmeasured — but fewer observations than the adopter accepts.
    $result = threshold(new Score(2, 0, 0, 0), [], minimumObservations: 5);

    expect($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Insufficient);
});

it('leaves a genuine failure reported as not met rather than insufficient', function (): void {
    // Coverage is fine and the floor is satisfied; the boundary simply did not hold.
    $result = threshold(new Score(passed: 1, failed: 3, errors: 0, pending: 0));

    expect($result->disposition())->toBe(LiveEvaluationThresholdDisposition::NotMet);
});

it('defaults to no absolute floor so coverage is the only added gate', function (): void {
    $result = threshold(new Score(1, 0, 0, 0));

    expect($result->minimumObservations)->toBe(0)
        ->and($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Met);
});
