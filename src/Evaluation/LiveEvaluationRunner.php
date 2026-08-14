<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\LiveEvaluationSuiteFactory;
use Fissible\Verdict\Contracts\LiveEvaluationTrialFactory;
use Fissible\Verdict\Support\SystemClock;
use InvalidArgumentException;
use LogicException;

final readonly class LiveEvaluationRunner
{
    public function __construct(
        private bool $liveEnabled,
        private int $maximumTrials,
    ) {}

    public function run(LiveEvaluationSuiteFactory $factory, LiveEvaluationOptions $options, ?Clock $clock = null): LiveEvaluationResult
    {
        $this->assertExecutionIsEnabled($options);
        $this->assertTrialsAreBounded($options);
        $this->assertTrialsAreIsolated($factory, $options);

        $clock ??= new SystemClock;
        $startedAt = $clock->now();

        /** @var array<string,LiveEvaluationScoreCounter> $counters keyed by case id, never by position */
        $counters = [];
        $identity = null;
        $suite = null;

        // A do/while, not a for: LiveEvaluationOptions rejects trials < 1, so at least one trial
        // always runs and $suite is always assigned before it is read below.
        $trial = 0;

        do {
            // Called before *every* trial, including the first: a process or database already used
            // before this run contaminates trial 0 exactly as it would trial 1.
            $suite = $factory instanceof LiveEvaluationTrialFactory
                ? $factory->makeForTrial($trial)
                : $factory->make();

            if ($identity === null) {
                $identity = TrialSuiteIdentity::of($suite);

                foreach ($suite->cases as $case) {
                    $counters[$case->id] = new LiveEvaluationScoreCounter;
                }
            } else {
                $identity->assertMatches($suite, $trial);
            }

            $result = $suite->run($clock);

            foreach ($result->cases as $case) {
                $counters[$case->id]->record($case->status, $case->errorClass);
            }

            $trial++;
        } while ($trial < $options->trials);

        // $suite is the final trial's. Everything read from it below — case metadata, suite name
        // and version, and the reproduction record naming the model and configuration — is covered
        // by the identity check every trial after the first passes, so reporting the last trial's
        // copy reports what all of them ran under.
        $cases = array_map(
            static fn (EvaluationCase $case): LiveEvaluationCaseResult => new LiveEvaluationCaseResult(
                id: $case->id,
                version: $case->version,
                purpose: $case->purpose,
                trustedSetupFingerprint: $case->input->trustedSetupFingerprint(),
                untrustedInputFingerprint: $case->input->untrustedInputFingerprint(),
                score: $counters[$case->id]->score(),
                errorBreakdown: $counters[$case->id]->errorBreakdown(),
            ),
            $suite->cases,
        );

        return new LiveEvaluationResult(
            suite: $suite->name,
            version: $suite->version,
            reproduction: $suite->reproduction,
            trials: $options->trials,
            startedAt: $startedAt,
            completedAt: $clock->now(),
            cases: $cases,
            securityThreshold: $this->threshold($cases, CasePurpose::Security, $options->minimumSecurityPassRate, $options->minimumObservations),
            utilityThreshold: $this->threshold($cases, CasePurpose::Utility, $options->minimumUtilityPassRate, $options->minimumObservations),
        );
    }

    private function assertExecutionIsEnabled(LiveEvaluationOptions $options): void
    {
        if (! $this->liveEnabled) {
            throw new LogicException('Live evaluation is disabled by verdict.evaluation.live_enabled.');
        }

        if (! $options->enabled) {
            throw new LogicException('Live evaluation requires an explicit enabled: true option.');
        }
    }

    /**
     * Refuse a multi-trial run the factory cannot make independent, before any model is invoked.
     *
     * A single trial makes no independence claim, so it needs no reset seam and is left unchanged.
     * See [ADR 0020](../../docs/adr/0020-live-trial-isolation-is-application-owned.md).
     */
    private function assertTrialsAreIsolated(LiveEvaluationSuiteFactory $factory, LiveEvaluationOptions $options): void
    {
        if ($options->trials > 1 && ! $factory instanceof LiveEvaluationTrialFactory) {
            throw LiveEvaluationRequiresTrialIsolation::forTrials($options->trials, $factory::class);
        }
    }

    private function assertTrialsAreBounded(LiveEvaluationOptions $options): void
    {
        if ($this->maximumTrials < 1) {
            throw new InvalidArgumentException('The Verdict live evaluation maximum trials configuration must be a positive integer.');
        }

        if ($options->trials > $this->maximumTrials) {
            throw new InvalidArgumentException("Live evaluation trials may not exceed the configured maximum of {$this->maximumTrials}.");
        }
    }

    /** @param list<LiveEvaluationCaseResult> $cases */
    private function threshold(
        array $cases,
        CasePurpose $purpose,
        float $minimumPassRate,
        int $minimumObservations,
    ): LiveEvaluationThreshold {
        $passed = 0;
        $failed = 0;
        $errors = 0;
        $pending = 0;
        /** @var array<string,int> $breakdown */
        $breakdown = [];

        foreach ($cases as $case) {
            if ($case->purpose !== $purpose) {
                continue;
            }

            $passed += $case->score->passed;
            $failed += $case->score->failed;
            $errors += $case->score->errors;
            $pending += $case->score->pending;

            // Summed across the purpose so coverage can tell an outcome that could have been a
            // measurement apart from one that never could. Sparse, like the per-case map.
            foreach ($case->errorBreakdown as $category => $count) {
                $breakdown[$category] = ($breakdown[$category] ?? 0) + $count;
            }
        }

        $score = new Score($passed, $failed, $errors, $pending);

        return new LiveEvaluationThreshold(
            purpose: $purpose,
            minimumPassRate: $minimumPassRate,
            score: $score,
            coverage: ThresholdCoverage::from($score, $breakdown),
            minimumObservations: $minimumObservations,
        );
    }
}
