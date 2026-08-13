<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\CaseNotLiveExpressible;
use Fissible\Verdict\Evaluation\CaseStatus;
use Fissible\Verdict\Evaluation\LiveEvaluationScoreCounter;
use Fissible\Verdict\Evaluation\LiveObservationUnavailable;
use Fissible\Verdict\Evaluation\ModelDeclinedToAct;

it('keeps each live error class separately countable', function (): void {
    $counter = new LiveEvaluationScoreCounter;
    $counter->record(CaseStatus::Passed);
    $counter->record(CaseStatus::Error, ModelDeclinedToAct::class);
    $counter->record(CaseStatus::Error, ModelDeclinedToAct::class);
    $counter->record(CaseStatus::Error, CaseNotLiveExpressible::class);
    $counter->record(CaseStatus::Error, LiveObservationUnavailable::class);
    $counter->record(CaseStatus::Error, RuntimeException::class);

    expect($counter->errorBreakdown())->toBe([
        'declined' => 2,
        'not_expressible' => 1,
        'unavailable' => 1,
        'uncategorized' => 1,
    ])->and($counter->score()->errors)->toBe(5)
        ->and($counter->score()->passRate())->toBe(1.0);
});
