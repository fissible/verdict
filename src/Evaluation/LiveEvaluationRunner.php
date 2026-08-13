<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Support\SystemClock;
use InvalidArgumentException;
use LogicException;

final readonly class LiveEvaluationRunner
{
    public function __construct(
        private bool $liveEnabled,
        private int $maximumTrials,
    ) {}

    public function run(SecuritySuite $suite, LiveEvaluationOptions $options, ?Clock $clock = null): LiveEvaluationResult
    {
        $this->assertExecutionIsEnabled($options);
        $this->assertTrialsAreBounded($options);

        $clock ??= new SystemClock;
        $startedAt = $clock->now();
        $counters = array_map(
            static fn (EvaluationCase $case): LiveEvaluationScoreCounter => new LiveEvaluationScoreCounter,
            $suite->cases,
        );

        for ($trial = 0; $trial < $options->trials; $trial++) {
            $result = $suite->run($clock);

            foreach ($result->cases as $index => $case) {
                $counters[$index]->record($case->status, $case->errorClass);
            }
        }

        $cases = array_map(
            static fn (EvaluationCase $case, LiveEvaluationScoreCounter $counter): LiveEvaluationCaseResult => new LiveEvaluationCaseResult(
                id: $case->id,
                version: $case->version,
                purpose: $case->purpose,
                trustedSetupFingerprint: $case->input->trustedSetupFingerprint(),
                untrustedInputFingerprint: $case->input->untrustedInputFingerprint(),
                score: $counter->score(),
                errorBreakdown: $counter->errorBreakdown(),
            ),
            $suite->cases,
            $counters,
        );

        return new LiveEvaluationResult(
            suite: $suite->name,
            version: $suite->version,
            reproduction: $suite->reproduction,
            trials: $options->trials,
            startedAt: $startedAt,
            completedAt: $clock->now(),
            cases: $cases,
            securityThreshold: $this->threshold($cases, CasePurpose::Security, $options->minimumSecurityPassRate),
            utilityThreshold: $this->threshold($cases, CasePurpose::Utility, $options->minimumUtilityPassRate),
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
    private function threshold(array $cases, CasePurpose $purpose, float $minimumPassRate): LiveEvaluationThreshold
    {
        $passed = 0;
        $failed = 0;
        $errors = 0;
        $pending = 0;

        foreach ($cases as $case) {
            if ($case->purpose !== $purpose) {
                continue;
            }

            $passed += $case->score->passed;
            $failed += $case->score->failed;
            $errors += $case->score->errors;
            $pending += $case->score->pending;
        }

        return new LiveEvaluationThreshold($purpose, $minimumPassRate, new Score($passed, $failed, $errors, $pending));
    }
}
