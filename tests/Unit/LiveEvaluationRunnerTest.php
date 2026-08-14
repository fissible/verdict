<?php

declare(strict_types=1);

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\CasePurpose;
use Fissible\Verdict\Evaluation\EvaluationCase;
use Fissible\Verdict\Evaluation\LiveEvaluationOptions;
use Fissible\Verdict\Evaluation\LiveEvaluationRunner;
use Fissible\Verdict\Evaluation\LiveEvaluationThresholdDisposition;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\ReproductionMetadata;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Tests\Support\FixedSuiteTrialFactory;

function liveEvaluationSuite(
    Closure $securityRunner,
    Closure $utilityRunner,
    string $secret = 'live-evaluation-secret',
): SecuritySuite {
    return new SecuritySuite(
        name: 'live-provider-suite',
        version: '1',
        cases: [
            EvaluationCase::attack(
                id: 'security-case',
                version: '1',
                input: new CaseInput(['policy' => 'live-policy@1'], ['prompt' => $secret]),
                runner: $securityRunner,
                assertions: [Assertions::notExecuted()],
            ),
            EvaluationCase::utility(
                id: 'utility-case',
                version: '1',
                input: new CaseInput(['policy' => 'live-policy@1'], ['prompt' => $secret]),
                runner: $utilityRunner,
                assertions: [Assertions::executed()],
            ),
        ],
        reproduction: new ReproductionMetadata([
            'provider' => 'application-selected@1',
            'policy' => 'live-policy@1',
        ]),
    );
}

it('aggregates mixed passed failed and error live trials without retaining raw fixtures', function (): void {
    $securityTrial = 0;
    $utilityTrial = 0;
    $secret = 'do-not-retain-live-prompt-or-output';
    $suite = liveEvaluationSuite(
        function () use (&$securityTrial, $secret): Observation {
            $securityTrial++;

            return match ($securityTrial) {
                1 => new Observation(Disposition::Deny, false, $secret),
                2 => new Observation(Disposition::Permit, true, $secret),
                default => throw new RuntimeException('Provider error containing '.$secret),
            };
        },
        function () use (&$utilityTrial, $secret): Observation {
            $utilityTrial++;

            return match ($utilityTrial) {
                1, 2 => new Observation(Disposition::Permit, true, $secret),
                default => new Observation(Disposition::Deny, false, $secret),
            };
        },
        $secret,
    );

    $result = (new LiveEvaluationRunner(liveEnabled: true, maximumTrials: 25))->run(new FixedSuiteTrialFactory($suite),
        new LiveEvaluationOptions(
            trials: 3,
            minimumSecurityPassRate: 0.75,
            minimumUtilityPassRate: 0.6,
            enabled: true,
        ),
    );
    $report = $result->report()->toArray();
    $json = $result->report()->toJson();

    expect($securityTrial)->toBe(3)
        ->and($utilityTrial)->toBe(3)
        ->and($result->securityThreshold->score->passed)->toBe(1)
        ->and($result->securityThreshold->score->failed)->toBe(1)
        ->and($result->securityThreshold->score->errors)->toBe(1)
        ->and($result->securityThreshold->score->passRate())->toBe(0.5)
        ->and($result->securityThreshold->disposition())->toBe(LiveEvaluationThresholdDisposition::NotMet)
        ->and($result->utilityThreshold->score->passed)->toBe(2)
        ->and($result->utilityThreshold->score->failed)->toBe(1)
        ->and($result->utilityThreshold->score->errors)->toBe(0)
        ->and($result->utilityThreshold->disposition())->toBe(LiveEvaluationThresholdDisposition::Met)
        ->and($report['schema'])->toBe('verdict.live-evaluation-report.v1')
        ->and($report['trials'])->toBe(3)
        ->and($report['reproduction'])->toBe(['provider' => 'application-selected@1', 'policy' => 'live-policy@1'])
        ->and($report['thresholds']['security']['minimum_pass_rate'])->toBe(0.75)
        ->and($report['thresholds']['security']['disposition'])->toBe('not_met')
        ->and($report['thresholds']['utility']['minimum_pass_rate'])->toBe(0.6)
        ->and($report['thresholds']['utility']['disposition'])->toBe('met')
        ->and($report['cases'][0]['score'])->toMatchArray(['passed' => 1, 'failed' => 1, 'errors' => 1, 'pass_rate' => 0.5])
        ->and($json)->not->toContain($secret);
});

it('includes pending live trials in the report score', function (): void {
    $suite = new SecuritySuite(
        name: 'blocked-live-suite',
        version: '1',
        cases: [EvaluationCase::pending(
            id: 'blocked-live-case',
            version: '1',
            purpose: CasePurpose::Security,
            input: new CaseInput([], ['case' => 'blocked-live-case']),
            blockedBy: '#30 derivation edges',
        )],
    );

    $result = (new LiveEvaluationRunner(true, 25))->run(new FixedSuiteTrialFactory($suite),
        new LiveEvaluationOptions(2, 1, 1, enabled: true),
    );
    $report = $result->report()->toArray();

    expect($result->securityThreshold->score->pending)->toBe(2)
        ->and($report['thresholds']['security'])->toMatchArray([
            'passed' => 0,
            'failed' => 0,
            'errors' => 0,
            'pending' => 2,
            'evaluated' => 0,
            'total' => 2,
            'pass_rate' => null,
        ])
        ->and($report['cases'][0]['score'])->toMatchArray([
            'pending' => 2,
            'total' => 2,
        ]);
});

it('blocks live execution when either opt-in is absent', function (): void {
    $calls = 0;
    $suite = liveEvaluationSuite(
        function () use (&$calls): Observation {
            $calls++;

            return new Observation(Disposition::Deny, false);
        },
        fn (): Observation => new Observation(Disposition::Permit, true),
    );
    $enabled = new LiveEvaluationOptions(1, 1, 1, enabled: true);
    $notExplicitlyEnabled = new LiveEvaluationOptions(1, 1, 1);

    expect(fn (): mixed => (new LiveEvaluationRunner(false, 25))->run(new FixedSuiteTrialFactory($suite), $enabled))
        ->toThrow(LogicException::class, 'verdict.evaluation.live_enabled')
        ->and(fn (): mixed => (new LiveEvaluationRunner(true, 25))->run(new FixedSuiteTrialFactory($suite), $notExplicitlyEnabled))
        ->toThrow(LogicException::class, 'enabled: true')
        ->and($calls)->toBe(0);
});

it('enforces the hard maximum before calling a live case', function (): void {
    $calls = 0;
    $suite = liveEvaluationSuite(
        function () use (&$calls): Observation {
            $calls++;

            return new Observation(Disposition::Deny, false);
        },
        fn (): Observation => new Observation(Disposition::Permit, true),
    );

    expect(fn (): mixed => (new LiveEvaluationRunner(true, 2))->run(new FixedSuiteTrialFactory($suite),
        new LiveEvaluationOptions(3, 1, 1, enabled: true),
    ))->toThrow(InvalidArgumentException::class, 'configured maximum of 2')
        ->and($calls)->toBe(0);
});

it('does not implicitly retry a provider exception', function (): void {
    $calls = 0;
    $suite = liveEvaluationSuite(
        function () use (&$calls): never {
            $calls++;

            throw new RuntimeException('Provider unavailable.');
        },
        fn (): Observation => new Observation(Disposition::Permit, true),
    );

    $result = (new LiveEvaluationRunner(true, 25))->run(new FixedSuiteTrialFactory($suite),
        new LiveEvaluationOptions(2, 1, 1, enabled: true),
    );

    expect($calls)->toBe(2)
        ->and($result->securityThreshold->score->passed)->toBe(0)
        ->and($result->securityThreshold->score->failed)->toBe(0)
        ->and($result->securityThreshold->score->errors)->toBe(2)
        ->and($result->securityThreshold->score->passRate())->toBeNull()
        ->and($result->securityThreshold->disposition())->toBe(LiveEvaluationThresholdDisposition::NotEvaluated);
});

it('evaluates security and utility thresholds independently', function (): void {
    $suite = liveEvaluationSuite(
        fn (): Observation => new Observation(Disposition::Deny, false),
        fn (): Observation => new Observation(Disposition::Deny, false),
    );

    $result = (new LiveEvaluationRunner(true, 25))->run(new FixedSuiteTrialFactory($suite),
        new LiveEvaluationOptions(1, 1, 0.5, enabled: true),
    );

    expect($result->securityThreshold->disposition())->toBe(LiveEvaluationThresholdDisposition::Met)
        ->and($result->utilityThreshold->disposition())->toBe(LiveEvaluationThresholdDisposition::NotMet);
});
