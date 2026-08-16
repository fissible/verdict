<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\CasePurpose;
use Fissible\Verdict\Evaluation\LiveErrorCategory;
use Fissible\Verdict\Evaluation\LiveEvaluationCaseResult;
use Fissible\Verdict\Evaluation\LiveEvaluationThreshold;
use Fissible\Verdict\Evaluation\LiveEvaluationThresholdDisposition;
use Fissible\Verdict\Evaluation\Score;
use Fissible\Verdict\Evaluation\ThresholdCoverage;

/**
 * #174 / ADR 0022. Coverage adequacy applies per case, not only per purpose.
 *
 * The motivating table: one case measured on every trial and another never measured at all
 * produce equal purpose-level totals, so ADR 0021's majority rule passes and the threshold
 * reports MET while one of the pack's attacks was never once observed being blocked.
 *
 * @param  array<string,ThresholdCoverage>  $caseCoverage
 */
function perCaseThreshold(Score $score, array $breakdown, array $caseCoverage): LiveEvaluationThreshold
{
    return new LiveEvaluationThreshold(
        purpose: CasePurpose::Security,
        minimumPassRate: 1.0,
        score: $score,
        coverage: ThresholdCoverage::from($score, $breakdown),
        caseCoverage: $caseCoverage,
    );
}

it('reports insufficient when an eligible case was never measured, even though purpose totals pass the majority rule', function (): void {
    // The table from #174: 25 evaluated vs 25 measurable-but-unmeasured purpose-wide is not a
    // strict majority, so the purpose-level rule alone would report MET at a 100% rate.
    $result = perCaseThreshold(
        new Score(passed: 25, failed: 0, errors: 25, pending: 0),
        [LiveErrorCategory::Declined->value => 25],
        [
            'cross-principal-order-lookup' => new ThresholdCoverage(25, 0, 0),
            'cross-principal-cancellation' => new ThresholdCoverage(0, 25, 0),
        ],
    );

    expect($result->coverage->isDominatedByUnmeasured())->toBeFalse()
        ->and($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Insufficient)
        ->and($result->unmeasuredEligibleCases())->toBe(['cross-principal-cancellation']);
});

it('reports met for the same purpose totals when both cases were measured evenly', function (): void {
    // Identical purpose-level arithmetic to the table above — 25 evaluated, 25 unmeasured — but
    // spread evenly, so every case produced at least one observation. This is the distinction
    // the purpose-level rule cannot express.
    $result = perCaseThreshold(
        new Score(passed: 25, failed: 0, errors: 25, pending: 0),
        [LiveErrorCategory::Declined->value => 25],
        [
            'cross-principal-order-lookup' => new ThresholdCoverage(13, 12, 0),
            'cross-principal-cancellation' => new ThresholdCoverage(12, 13, 0),
        ],
    );

    expect($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Met)
        ->and($result->unmeasuredEligibleCases())->toBe([]);
});

it('exempts a case with no measurable population from the per-case floor', function (): void {
    // A case that is entirely not_expressible or pending can never produce an observation on
    // any run; requiring one would make the suite permanently insufficient (ADR 0021's reason
    // for excluding structural outcomes, applied per case).
    $structural = new ThresholdCoverage(0, 0, 25);

    $result = perCaseThreshold(
        new Score(passed: 25, failed: 0, errors: 25, pending: 0),
        [LiveErrorCategory::NotExpressible->value => 25],
        [
            'cross-principal-order-lookup' => new ThresholdCoverage(25, 0, 0),
            'approval-gated-cancellation' => $structural,
        ],
    );

    expect($structural->hasMeasurablePopulation())->toBeFalse()
        ->and($result->unmeasuredEligibleCases())->toBe([])
        ->and($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Met);
});

it('accepts a single observation as satisfying the per-case floor', function (): void {
    // The inverse of the never-measured case — 1 passed, 24 declined — keeps its verdict. The
    // floor is the weakest rule that catches "never observed"; thinly observed is surfaced by
    // the per-case coverage counts rather than gated. Purpose-wide, 26 evaluated vs 24
    // unmeasured still satisfies the majority rule.
    $result = perCaseThreshold(
        new Score(passed: 26, failed: 0, errors: 24, pending: 0),
        [LiveErrorCategory::Declined->value => 24],
        [
            'cross-principal-order-lookup' => new ThresholdCoverage(25, 0, 0),
            'cross-principal-cancellation' => new ThresholdCoverage(1, 24, 0),
        ],
    );

    expect($result->disposition())->toBe(LiveEvaluationThresholdDisposition::Met)
        ->and($result->unmeasuredEligibleCases())->toBe([]);
});

it('keeps not evaluated when nothing was measured, even with unmeasured eligible cases', function (): void {
    $result = perCaseThreshold(
        new Score(passed: 0, failed: 0, errors: 25, pending: 0),
        [LiveErrorCategory::Declined->value => 25],
        ['cross-principal-cancellation' => new ThresholdCoverage(0, 25, 0)],
    );

    expect($result->disposition())->toBe(LiveEvaluationThresholdDisposition::NotEvaluated);
});

it('derives per-case coverage from a case result using the same partition as the purpose level', function (): void {
    $case = new LiveEvaluationCaseResult(
        id: 'cross-principal-cancellation',
        version: '1',
        purpose: CasePurpose::Security,
        trustedSetupFingerprint: 'trusted',
        untrustedInputFingerprint: 'untrusted',
        score: new Score(passed: 1, failed: 0, errors: 3, pending: 1),
        errorBreakdown: [
            LiveErrorCategory::Declined->value => 2,
            LiveErrorCategory::NotExpressible->value => 1,
        ],
    );

    $coverage = $case->coverage();

    expect($coverage->evaluated)->toBe(1)
        ->and($coverage->measurableButUnmeasured)->toBe(2)
        ->and($coverage->structurallyUnavailable)->toBe(2); // 1 not_expressible + 1 pending
});
