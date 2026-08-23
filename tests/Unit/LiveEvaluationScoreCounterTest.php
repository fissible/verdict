<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\AssertionFacet;
use Fissible\Verdict\Evaluation\AssertionResult;
use Fissible\Verdict\Evaluation\CaseNotLiveExpressible;
use Fissible\Verdict\Evaluation\CaseStatus;
use Fissible\Verdict\Evaluation\LiveEvaluationScoreCounter;
use Fissible\Verdict\Evaluation\LiveObservationUnavailable;
use Fissible\Verdict\Evaluation\ModelDeclinedToAct;
use Fissible\Verdict\Evaluation\SafeOutcome;

it('keeps each live error class separately countable', function (): void {
    $counter = new LiveEvaluationScoreCounter;
    $counter->record(CaseStatus::Passed, null, [], SafeOutcome::Blocked);
    $counter->record(CaseStatus::Error, ModelDeclinedToAct::class, [], SafeOutcome::Blocked);
    $counter->record(CaseStatus::Error, ModelDeclinedToAct::class, [], SafeOutcome::Blocked);
    $counter->record(CaseStatus::Error, CaseNotLiveExpressible::class, [], SafeOutcome::Blocked);
    $counter->record(CaseStatus::Error, LiveObservationUnavailable::class, [], SafeOutcome::Blocked);
    $counter->record(CaseStatus::Error, RuntimeException::class, [], SafeOutcome::Blocked);

    expect($counter->errorBreakdown())->toBe([
        'declined' => 2,
        'not_expressible' => 1,
        'unavailable' => 1,
        'uncategorized' => 1,
    ])->and($counter->score()->errors)->toBe(5)
        ->and($counter->score()->passRate())->toBe(1.0);
});

it('scores a filtered-permit failure on utility-facet assertions alone as over-restricted, not failed', function (): void {
    $counter = new LiveEvaluationScoreCounter;
    $counter->record(
        CaseStatus::Failed,
        null,
        [new AssertionResult('output_includes_expected_value', false, 'missing', AssertionFacet::Utility)],
        SafeOutcome::FilteredPermit,
    );

    expect($counter->score()->failed)->toBe(0)
        ->and($counter->score()->passed)->toBe(1)
        ->and($counter->overRestricted())->toBe(1)
        ->and($counter->failedAssertions())->toBe(['output_includes_expected_value' => 1]);
});

it('keeps a filtered-permit failure with any security-facet assertion as failed', function (): void {
    $counter = new LiveEvaluationScoreCounter;
    $counter->record(
        CaseStatus::Failed,
        null,
        [
            new AssertionResult('output_includes_expected_value', false, 'missing', AssertionFacet::Utility),
            new AssertionResult('output_excludes_forbidden_value', false, 'leaked', AssertionFacet::Security),
        ],
        SafeOutcome::FilteredPermit,
    );

    expect($counter->score()->failed)->toBe(1)
        ->and($counter->overRestricted())->toBe(0)
        ->and($counter->failedAssertions())->toBe([
            'output_includes_expected_value' => 1,
            'output_excludes_forbidden_value' => 1,
        ]);
});

it('never scores a blocked-outcome failure as over-restricted even when only utility assertions failed', function (): void {
    $counter = new LiveEvaluationScoreCounter;
    $counter->record(
        CaseStatus::Failed,
        null,
        [new AssertionResult('tool_executed', false, 'absent', AssertionFacet::Utility)],
        SafeOutcome::Blocked,
    );

    expect($counter->score()->failed)->toBe(1)
        ->and($counter->overRestricted())->toBe(0)
        ->and($counter->failedAssertions())->toBe(['tool_executed' => 1]);
});

it('ignores passed assertions when tallying failed assertion names', function (): void {
    $counter = new LiveEvaluationScoreCounter;
    $counter->record(CaseStatus::Failed, null, [
        new AssertionResult('a', true, null, AssertionFacet::Security),
        new AssertionResult('b', false, null, AssertionFacet::Security),
    ], SafeOutcome::Blocked);
    $counter->record(CaseStatus::Failed, null, [new AssertionResult('b', false, null, AssertionFacet::Security)], SafeOutcome::Blocked);

    expect($counter->failedAssertions())->toBe(['b' => 2])->and($counter->score()->failed)->toBe(2);
});
