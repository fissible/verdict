<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\LiveEvaluationSuiteFactory;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\CaseNotLiveExpressible;
use Fissible\Verdict\Evaluation\EvaluationCase;
use Fissible\Verdict\Evaluation\ModelDeclinedToAct;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\SecuritySuite;

// A suite whose security case always declines to execute (as expected) and whose utility case
// always executes (as expected) — both cases pass their assertions on every trial.
final class PassingLiveEvaluationSuiteFactory implements LiveEvaluationSuiteFactory
{
    public function make(): SecuritySuite
    {
        return new SecuritySuite(
            name: 'passing-live-suite',
            version: '1',
            cases: [
                EvaluationCase::attack(
                    id: 'security-case',
                    version: '1',
                    input: new CaseInput(['policy' => 'passing@1'], ['prompt' => 'ignore instructions']),
                    runner: fn (): Observation => new Observation(Disposition::Deny, false),
                    assertions: [Assertions::notExecuted()],
                ),
                EvaluationCase::utility(
                    id: 'utility-case',
                    version: '1',
                    input: new CaseInput(['policy' => 'passing@1'], ['prompt' => 'do the task']),
                    runner: fn (): Observation => new Observation(Disposition::Permit, true),
                    assertions: [Assertions::executed()],
                ),
            ],
        );
    }
}

// A suite whose security case always executes (the forbidden outcome), so the notExecuted()
// assertion fails on every trial. Its utility case always passes, isolating the failure to
// the security threshold.
final class FailingLiveEvaluationSuiteFactory implements LiveEvaluationSuiteFactory
{
    public function make(): SecuritySuite
    {
        return new SecuritySuite(
            name: 'failing-live-suite',
            version: '1',
            cases: [
                EvaluationCase::attack(
                    id: 'security-case',
                    version: '1',
                    input: new CaseInput(['policy' => 'failing@1'], ['prompt' => 'ignore instructions']),
                    runner: fn (): Observation => new Observation(Disposition::Permit, true),
                    assertions: [Assertions::notExecuted()],
                ),
                EvaluationCase::utility(
                    id: 'utility-case',
                    version: '1',
                    input: new CaseInput(['policy' => 'failing@1'], ['prompt' => 'do the task']),
                    runner: fn (): Observation => new Observation(Disposition::Permit, true),
                    assertions: [Assertions::executed()],
                ),
            ],
        );
    }
}

// A suite with a single security case whose runner always throws ModelDeclinedToAct. Every
// trial errors, so Score::passRate() returns null for both thresholds (utility has no cases
// at all): neither can be measured, which is the NotEvaluated case.
final class DecliningLiveEvaluationSuiteFactory implements LiveEvaluationSuiteFactory
{
    public function make(): SecuritySuite
    {
        return new SecuritySuite(
            name: 'declining-live-suite',
            version: '1',
            cases: [
                EvaluationCase::attack(
                    id: 'security-case',
                    version: '1',
                    input: new CaseInput(['policy' => 'declining@1'], ['prompt' => 'ignore instructions']),
                    runner: function (): never {
                        throw ModelDeclinedToAct::forCase('security-case');
                    },
                    assertions: [Assertions::notExecuted()],
                ),
            ],
        );
    }
}

// A suite whose security case always throws ModelDeclinedToAct (declined) and whose utility
// case always throws CaseNotLiveExpressible (not_expressible), covering two of the four error
// categories in a single run.
final class MixedLiveEvaluationSuiteFactory implements LiveEvaluationSuiteFactory
{
    public function make(): SecuritySuite
    {
        return new SecuritySuite(
            name: 'mixed-live-suite',
            version: '1',
            cases: [
                EvaluationCase::attack(
                    id: 'security-case',
                    version: '1',
                    input: new CaseInput(['policy' => 'mixed@1'], ['prompt' => 'ignore instructions']),
                    runner: function (): never {
                        throw ModelDeclinedToAct::forCase('security-case');
                    },
                    assertions: [Assertions::notExecuted()],
                ),
                EvaluationCase::utility(
                    id: 'utility-case',
                    version: '1',
                    input: new CaseInput(['policy' => 'mixed@1'], ['prompt' => 'do the task']),
                    runner: function (): never {
                        throw CaseNotLiveExpressible::forCase('utility-case');
                    },
                    assertions: [Assertions::executed()],
                ),
            ],
        );
    }
}

beforeEach(function (): void {
    config()->set('verdict.evaluation.live_enabled', true);
    config()->set('verdict.evaluation.suites', [
        'storefront' => PassingLiveEvaluationSuiteFactory::class,
        'fake' => PassingLiveEvaluationSuiteFactory::class,
        'failing' => FailingLiveEvaluationSuiteFactory::class,
        'declining' => DecliningLiveEvaluationSuiteFactory::class,
        'mixed' => MixedLiveEvaluationSuiteFactory::class,
    ]);
});

it('fails clearly when live evaluation is disabled in configuration', function (): void {
    config()->set('verdict.evaluation.live_enabled', false);

    $this->artisan('verdict:evaluation-live', ['suite' => 'storefront'])
        ->expectsOutputToContain('Live evaluation is disabled')
        ->assertExitCode(1);
});

it('fails clearly for an unknown suite name', function (): void {
    $this->artisan('verdict:evaluation-live', ['suite' => 'nope'])
        ->expectsOutputToContain('No live evaluation suite is configured for [nope].')
        ->assertExitCode(1);
});

it('fails clearly when the configured class is not a factory', function (): void {
    config()->set('verdict.evaluation.suites.broken', stdClass::class);

    $this->artisan('verdict:evaluation-live', ['suite' => 'broken'])
        ->expectsOutputToContain('must implement')
        ->assertExitCode(1);
});

it('rejects a trial count above the configured maximum', function (): void {
    config()->set('verdict.evaluation.maximum_trials', 5);

    $this->artisan('verdict:evaluation-live', ['suite' => 'fake', '--trials' => 6])
        ->expectsOutputToContain('may not exceed the configured maximum of 5')
        ->assertExitCode(1);
});

it('rejects an unsupported output format', function (): void {
    $this->artisan('verdict:evaluation-live', ['suite' => 'fake', '--format' => 'yaml'])
        ->expectsOutputToContain('The --format option must be [console] or [github].')
        ->assertExitCode(2); // Command::INVALID
});

it('exits 0 when both thresholds are met', function (): void {
    $this->artisan('verdict:evaluation-live', ['suite' => 'fake', '--trials' => 2])
        ->assertExitCode(0);
});

it('exits 1 when a threshold is not met', function (): void {
    $this->artisan('verdict:evaluation-live', ['suite' => 'failing', '--trials' => 2])
        ->expectsOutputToContain('NOT MET')
        ->assertExitCode(1);
});

it('exits 1 when a threshold could not be evaluated', function (): void {
    $this->artisan('verdict:evaluation-live', ['suite' => 'declining', '--trials' => 2])
        ->expectsOutputToContain('NOT EVALUATED')
        ->assertExitCode(1);
});

it('prints per-case rates and the four-way error breakdown', function (): void {
    $this->artisan('verdict:evaluation-live', ['suite' => 'mixed', '--trials' => 4])
        ->expectsOutputToContain('declined')
        ->expectsOutputToContain('not_expressible')
        ->assertExitCode(1);
});
