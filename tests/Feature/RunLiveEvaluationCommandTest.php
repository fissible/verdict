<?php

declare(strict_types=1);

use Fissible\Verdict\Console\Commands\CompareEvaluationCommand;
use Fissible\Verdict\Console\Commands\RunLiveEvaluationCommand;
use Fissible\Verdict\Contracts\LiveEvaluationTrialFactory;
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
final class PassingLiveEvaluationSuiteFactory implements LiveEvaluationTrialFactory
{
    /** Stateless: these fixtures return fixed results, so there is nothing for a trial to reset. */
    public function makeForTrial(int $trial): SecuritySuite
    {
        return $this->make();
    }

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
final class FailingLiveEvaluationSuiteFactory implements LiveEvaluationTrialFactory
{
    /** Stateless: these fixtures return fixed results, so there is nothing for a trial to reset. */
    public function makeForTrial(int $trial): SecuritySuite
    {
        return $this->make();
    }

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
final class DecliningLiveEvaluationSuiteFactory implements LiveEvaluationTrialFactory
{
    /** Stateless: these fixtures return fixed results, so there is nothing for a trial to reset. */
    public function makeForTrial(int $trial): SecuritySuite
    {
        return $this->make();
    }

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
final class MixedLiveEvaluationSuiteFactory implements LiveEvaluationTrialFactory
{
    /** Stateless: these fixtures return fixed results, so there is nothing for a trial to reset. */
    public function makeForTrial(int $trial): SecuritySuite
    {
        return $this->make();
    }

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

// --- --format=github: pin the exact emitted workflow command lines, not just the exit code. ---

it('emits a github ::notice line per threshold when both thresholds are met', function (): void {
    $this->artisan('verdict:evaluation-live', ['suite' => 'fake', '--trials' => 2, '--format' => 'github'])
        ->expectsOutput('::notice title=Verdict live evaluation%3A security::MET — 2 passed / 0 failed / 0 errors / 0 pending (100%25) (minimum 100%25)')
        ->expectsOutput('::notice title=Verdict live evaluation%3A utility::MET — 2 passed / 0 failed / 0 errors / 0 pending (100%25) (minimum 80%25)')
        ->assertExitCode(0);
});

it('emits a github ::error line for a threshold that is not met', function (): void {
    $this->artisan('verdict:evaluation-live', ['suite' => 'failing', '--trials' => 2, '--format' => 'github'])
        ->expectsOutput('::error title=Verdict live evaluation%3A security::NOT MET — 0 passed / 2 failed / 0 errors / 0 pending (0%25) (minimum 100%25)')
        ->expectsOutput('::notice title=Verdict live evaluation%3A utility::MET — 2 passed / 0 failed / 0 errors / 0 pending (100%25) (minimum 80%25)')
        ->assertExitCode(1);
});

it('emits a github ::error line for a threshold that could not be evaluated', function (): void {
    $this->artisan('verdict:evaluation-live', ['suite' => 'declining', '--trials' => 2, '--format' => 'github'])
        ->expectsOutput('::error title=Verdict live evaluation%3A security::NOT EVALUATED — 0 passed / 0 failed / 2 errors / 0 pending (no pass rate) (minimum 100%25)')
        ->expectsOutput('::error title=Verdict live evaluation%3A utility::NOT EVALUATED — 0 passed / 0 failed / 0 errors / 0 pending (no pass rate) (minimum 80%25)')
        ->expectsOutput('::notice title=Verdict live evaluation error breakdown::declined=2')
        ->assertExitCode(1);
});

// Boundary set by the plan owner: today, no arbitrary user-controlled suite name or case ID
// reaches RunLiveEvaluationCommand's github output — renderGithub() only ever interpolates a
// closed CasePurpose enum ('security'/'utility'), a closed LiveErrorCategory enum, and computed
// numbers (see the three ::notice/::error tests above, which pin the exact emitted lines,
// including the escape artifacts those fixed strings already produce). That absence of a free-
// text channel is why reflection-based parity on the escape helpers, plus those exact-line
// behavioral assertions, is proportionate coverage here rather than an end-to-end injection
// test. If a future change routes user-controlled content (a suite name, a case ID, anything
// from config or a report file) into that output, this test stops being sufficient: add an
// end-to-end test at that point that drives hostile characters (%, :, ,, CR, LF) through the
// actual emitted `::notice`/`::error` line, not just through the escape helpers in isolation.
it('escapes github workflow command text identically to CompareEvaluationCommand', function (): void {
    // GitHub workflow commands require %, \r and \n escaped in message text, and additionally
    // : and , escaped in property values (https://docs.github.com/actions - workflow commands).
    // RunLiveEvaluationCommand's escapeMessage()/escapeProperty() were written by mirroring
    // CompareEvaluationCommand's, but nothing pinned that they actually match byte-for-byte.
    // Drive text containing every character GitHub's protocol requires escaped through both
    // commands' private helpers directly, since RunLiveEvaluationCommand's github renderer has
    // no channel (unlike CompareEvaluationCommand's case_id) that carries free-form suite/case
    // text into the emitted line — the closest live-fire equivalent is the literal ":" already
    // present in its static "Verdict live evaluation: {purpose}" title, pinned above as %3A.
    $dangerous = '100% risky: pass, fail'."\r\n".'next line';

    $live = new RunLiveEvaluationCommand;
    $compare = new CompareEvaluationCommand;

    $liveMessage = new ReflectionMethod($live, 'escapeMessage');
    $liveProperty = new ReflectionMethod($live, 'escapeProperty');
    $compareMessage = new ReflectionMethod($compare, 'escapeMessage');
    $compareProperty = new ReflectionMethod($compare, 'escapeProperty');

    $liveMessageOut = $liveMessage->invoke($live, $dangerous);
    $livePropertyOut = $liveProperty->invoke($live, $dangerous);
    $compareMessageOut = $compareMessage->invoke($compare, $dangerous);
    $comparePropertyOut = $compareProperty->invoke($compare, $dangerous);

    expect($liveMessageOut)->toBe('100%25 risky: pass, fail%0D%0Anext line');
    expect($livePropertyOut)->toBe('100%25 risky%3A pass%2C fail%0D%0Anext line');

    // The substance of the check: identical bytes out of both commands for identical input.
    expect($liveMessageOut)->toBe($compareMessageOut);
    expect($livePropertyOut)->toBe($comparePropertyOut);
});
