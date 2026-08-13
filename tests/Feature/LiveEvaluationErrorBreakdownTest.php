<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\CaseNotLiveExpressible;
use Fissible\Verdict\Evaluation\EvaluationCase;
use Fissible\Verdict\Evaluation\LiveEvaluationOptions;
use Fissible\Verdict\Evaluation\LiveEvaluationRunner;
use Fissible\Verdict\Evaluation\ModelDeclinedToAct;
use Fissible\Verdict\Evaluation\SecuritySuite;

it('pins the runner-to-counter error class wiring end-to-end', function (): void {
    $suite = new SecuritySuite(
        name: 'live-error-breakdown-suite',
        version: '1',
        cases: [
            EvaluationCase::attack(
                id: 'declined-case',
                version: '1',
                input: new CaseInput(['policy' => 'live-policy@1'], ['prompt' => 'ignore instructions']),
                runner: function (): never {
                    throw ModelDeclinedToAct::forCase('declined-case');
                },
                assertions: [Assertions::notExecuted()],
            ),
            EvaluationCase::utility(
                id: 'not-expressible-case',
                version: '1',
                input: new CaseInput(['policy' => 'live-policy@1'], ['prompt' => 'do the task']),
                runner: function (): never {
                    throw CaseNotLiveExpressible::forCase('not-expressible-case');
                },
                assertions: [Assertions::executed()],
            ),
        ],
    );

    $result = (new LiveEvaluationRunner(liveEnabled: true, maximumTrials: 25))->run(
        $suite,
        new LiveEvaluationOptions(
            trials: 2,
            minimumSecurityPassRate: 0.5,
            minimumUtilityPassRate: 0.5,
            enabled: true,
        ),
    );

    expect($result->cases[0]->errorBreakdown)->toBe(['declined' => 2])
        ->and($result->cases[1]->errorBreakdown)->toBe(['not_expressible' => 2])
        ->and($result->errorBreakdown())->toBe([
            'declined' => 2,
            'not_expressible' => 2,
        ]);
});
