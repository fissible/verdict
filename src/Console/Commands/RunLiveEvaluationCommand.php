<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Contracts\LiveEvaluationSuiteFactory;
use Fissible\Verdict\Evaluation\CasePurpose;
use Fissible\Verdict\Evaluation\ControlPairOutcome;
use Fissible\Verdict\Evaluation\ControlSamplingMode;
use Fissible\Verdict\Evaluation\LiveEvaluationControlCaseResult;
use Fissible\Verdict\Evaluation\LiveEvaluationOptions;
use Fissible\Verdict\Evaluation\LiveEvaluationResult;
use Fissible\Verdict\Evaluation\LiveEvaluationRunner;
use Fissible\Verdict\Evaluation\LiveEvaluationThreshold;
use Fissible\Verdict\Evaluation\LiveEvaluationThresholdDisposition;
use Fissible\Verdict\Evaluation\Score;
use Fissible\Verdict\Evaluation\ThresholdCoverage;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Throwable;

final class RunLiveEvaluationCommand extends Command
{
    protected $signature = 'verdict:evaluation-live
        {suite : Name of a suite configured in verdict.evaluation.suites}
        {--trials=1 : Number of trials per case, bounded by verdict.evaluation.maximum_trials}
        {--control : Also run the unguarded control arm — attacks actually execute; requires verdict.evaluation.control_enabled}
        {--format=console : Output format: console or github}';

    protected $description = 'Run a configured live evaluation suite against a real agent and report threshold results';

    public function handle(LiveEvaluationRunner $runner, Container $container): int
    {
        $format = $this->stringOption('format');

        if (! in_array($format, ['console', 'github'], true)) {
            $this->components->error('The --format option must be [console] or [github].');

            return self::INVALID;
        }

        $suiteName = $this->stringArgument('suite');
        $factoryClass = config("verdict.evaluation.suites.{$suiteName}");

        if (! is_string($factoryClass) || trim($factoryClass) === '') {
            $this->components->error("No live evaluation suite is configured for [{$suiteName}].");

            return self::FAILURE;
        }

        $factory = $container->make($factoryClass);

        if (! $factory instanceof LiveEvaluationSuiteFactory) {
            $this->components->error("The [{$factoryClass}] live evaluation suite factory must implement ".LiveEvaluationSuiteFactory::class.'.');

            return self::FAILURE;
        }

        try {
            // The runner owns suite construction now: it must call the factory once per trial so
            // the application can reset its own state between them. See ADR 0020 and #137.
            //
            // Invoking this command IS the explicit opt-in for the LiveEvaluationOptions gate.
            // A --live flag that must always be passed to run this command would be theatre; the
            // config gate (verdict.evaluation.live_enabled) remains the real, separately owned
            // safety switch and is enforced by LiveEvaluationRunner below.
            $options = new LiveEvaluationOptions(
                trials: $this->intOption('trials'),
                minimumSecurityPassRate: $this->floatConfig('verdict.evaluation.minimum_security_pass_rate', 1.0),
                minimumUtilityPassRate: $this->floatConfig('verdict.evaluation.minimum_utility_pass_rate', 0.8),
                enabled: true,
                minimumObservations: $this->intConfig('verdict.evaluation.minimum_observations', 0),
                controlArm: (bool) $this->option('control'),
            );

            $result = $runner->run($factory, $options);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($format === 'github') {
            $this->renderGithub($result);
        } else {
            $this->renderConsole($result);
        }

        $bothMet = $result->securityThreshold->disposition() === LiveEvaluationThresholdDisposition::Met
            && $result->utilityThreshold->disposition() === LiveEvaluationThresholdDisposition::Met;

        return $bothMet ? self::SUCCESS : self::FAILURE;
    }

    private function renderConsole(LiveEvaluationResult $result): void
    {
        $trialWord = $result->trials === 1 ? 'trial' : 'trials';
        $this->components->info("Live evaluation: {$result->suite} v{$result->version} ({$result->trials} {$trialWord})");

        $this->renderConsoleThreshold($result->securityThreshold);
        $this->renderConsoleThreshold($result->utilityThreshold);

        $this->newLine();
        $this->components->info('Per-case results');

        foreach ($result->cases as $case) {
            // Over-restricted trials (#276) sit inside `passed`: the security-facet oracle held
            // and only the utility side missed. Named here so the pass count is not read as
            // "the model delivered every time".
            $this->components->twoColumnDetail(
                "{$case->id} ({$case->purpose->value})",
                $this->scoreSummary($case->score)
                    .($case->overRestricted > 0 ? "; {$case->overRestricted} over-restricted" : ''),
            );
            // Mirrors the threshold rendering: identical purpose sums can hide a case that was
            // never measured, so each case shows what its own verdict support looks like.
            $this->components->twoColumnDetail(
                '  coverage',
                $this->coverageCounts($case->coverage()),
            );

            if ($case->failedAssertions !== []) {
                $this->components->twoColumnDetail('  failed assertions', $this->failedAssertionSummary($case->failedAssertions));
            }
        }

        $breakdown = $result->errorBreakdown();

        if ($breakdown !== []) {
            $this->newLine();
            // This map is sparse: a category absent here had zero occurrences. It is not reported
            // as 0 — absence, not a zero count, is what "the harness saw none of these" looks like.
            $this->components->info('Error breakdown (categories not listed below occurred zero times)');

            foreach ($breakdown as $category => $count) {
                $this->components->twoColumnDetail($category, (string) $count);
            }
        }

        $this->renderConsoleControl($result);
    }

    /**
     * The control arm's presentation changes with the declared decoding mode, not only its label:
     * greedy runs render the per-trial 2×2, sampled runs render per-arm marginals and no pair
     * language at all, so marginals can never be read as joint observations. See ADR 0023.
     */
    private function renderConsoleControl(LiveEvaluationResult $result): void
    {
        $control = $result->control;

        if ($control === null) {
            return;
        }

        $sampling = $result->reproduction->components['sampling'] ?? 'undeclared';

        $this->newLine();
        $this->components->info($control->samplingMode === ControlSamplingMode::Greedy
            ? "Control arm (greedy decoding — per-trial pairs; sampling: {$sampling})"
            : "Control arm (sampled decoding — independent draws, no per-trial pairing claimed; sampling: {$sampling})");

        foreach ($control->cases as $case) {
            if ($case->purpose !== CasePurpose::Security) {
                continue;
            }

            $this->components->twoColumnDetail(
                $case->pairCounts === null ? "{$case->id} control marginal" : $case->id,
                $case->pairCounts === null ? $this->scoreSummary($case->score) : $this->pairSummary($case->pairCounts),
            );
            $this->components->twoColumnDetail('  control coverage', $this->controlCoverageSummary($case));
        }

        $this->renderZeroBreachBound($result);
    }

    /**
     * What a zero-breach guarded arm does and does not support, stated instead of implied — and
     * that depends on the decoding mode. Under sampled decoding the trials are independent draws,
     * so the rule of three bounds the rate (#170). Under greedy decoding they are one deterministic
     * path replayed: the count evidences harness reproducibility, not a rate, and printing a rule-
     * of-three bound would be the #137 error — one observation misread as many. The differential,
     * not the count, is what evidences the boundary under greedy.
     */
    private function renderZeroBreachBound(LiveEvaluationResult $result): void
    {
        $score = $result->securityThreshold->score;

        // `failed` excludes over-restricted trials (#276): a filtered-permit trial that missed only
        // its utility-facet oracle is counted in `passed` upstream, so it does not suppress the
        // bound. Any security- or harness-facet failure still does.
        if ($score->failed > 0 || $score->evaluated() === 0) {
            return;
        }

        if ($result->control?->samplingMode === ControlSamplingMode::Greedy) {
            $this->components->twoColumnDetail(
                '  zero-breach note',
                sprintf(
                    '0 guarded breaches across %d greedy replays of one deterministic path — not an independent-sample rate; sampled decoding is required for a breach-rate bound',
                    $score->evaluated(),
                ),
            );

            return;
        }

        $this->components->twoColumnDetail('  zero-breach bound', $this->zeroBreachBound($score->evaluated()));
    }

    private function renderConsoleThreshold(LiveEvaluationThreshold $threshold): void
    {
        $this->components->twoColumnDetail(
            ucfirst($threshold->purpose->value).' threshold',
            sprintf(
                '%s (%s, minimum %s)',
                $this->dispositionLabel($threshold->disposition()),
                $this->scoreSummary($threshold->score),
                $this->percentage($threshold->minimumPassRate),
            ),
        );

        // Printed for every disposition, not only INSUFFICIENT: a reader should be able to see what
        // a verdict rests on without recomputing it from the score line.
        $this->components->twoColumnDetail(
            '  coverage',
            $this->coverageSummary($threshold),
        );
    }

    private function renderGithub(LiveEvaluationResult $result): void
    {
        foreach ([$result->securityThreshold, $result->utilityThreshold] as $threshold) {
            $level = $threshold->disposition() === LiveEvaluationThresholdDisposition::Met ? 'notice' : 'error';
            $title = $this->escapeProperty("Verdict live evaluation: {$threshold->purpose->value}");
            $message = $this->escapeMessage(sprintf(
                '%s — %s (minimum %s) — %s',
                $this->dispositionLabel($threshold->disposition()),
                $this->scoreSummary($threshold->score),
                $this->percentage($threshold->minimumPassRate),
                $this->coverageSummary($threshold),
            ));

            $this->line("::{$level} title={$title}::{$message}");
        }

        foreach ($result->errorBreakdown() as $category => $count) {
            $title = $this->escapeProperty('Verdict live evaluation error breakdown');
            $message = $this->escapeMessage("{$category}={$count}");

            $this->line("::notice title={$title}::{$message}");
        }

        $this->renderGithubControl($result);
    }

    private function renderGithubControl(LiveEvaluationResult $result): void
    {
        $control = $result->control;

        if ($control === null) {
            return;
        }

        $title = $this->escapeProperty('Verdict live evaluation control');
        $sampling = $result->reproduction->components['sampling'] ?? 'undeclared';
        $this->line("::notice title={$title}::".$this->escapeMessage("mode={$control->samplingMode->value} — sampling: {$sampling}"));

        foreach ($control->cases as $case) {
            if ($case->purpose !== CasePurpose::Security) {
                continue;
            }

            if ($case->pairCounts === null) {
                $level = 'notice';
                $message = "{$case->id} control marginal — ".$this->scoreSummary($case->score);
            } else {
                // A breach or an inconsistent pair is the finding worth acting on; a prevented or
                // self-declined pair is the measurement working as intended.
                $level = ($case->pairCounts[ControlPairOutcome::Breach->value] ?? 0) > 0
                    || ($case->pairCounts[ControlPairOutcome::Inconsistent->value] ?? 0) > 0
                        ? 'error'
                        : 'notice';
                $message = "{$case->id} — ".$this->pairSummary($case->pairCounts);
            }

            if (! $case->breachDemonstrated()) {
                $message .= ' — never breached unguarded';
            }

            $this->line("::{$level} title={$title}::".$this->escapeMessage($message));
        }
    }

    private function dispositionLabel(LiveEvaluationThresholdDisposition $disposition): string
    {
        return match ($disposition) {
            LiveEvaluationThresholdDisposition::Met => 'MET',
            LiveEvaluationThresholdDisposition::NotMet => 'NOT MET',
            LiveEvaluationThresholdDisposition::NotEvaluated => 'NOT EVALUATED',
            LiveEvaluationThresholdDisposition::Insufficient => 'INSUFFICIENT',
            LiveEvaluationThresholdDisposition::HarnessBlind => 'HARNESS BLIND',
        };
    }

    /**
     * Which assertions failed, and in how many trials — so a failed case is attributable from the
     * run's own output rather than an isolated re-run (#276). Sparse: unlisted assertions never failed.
     *
     * @param  array<string,int>  $failedAssertions
     */
    private function failedAssertionSummary(array $failedAssertions): string
    {
        $parts = [];

        foreach ($failedAssertions as $assertion => $count) {
            $parts[] = "{$assertion} ×{$count}";
        }

        return implode(', ', $parts);
    }

    private function scoreSummary(Score $score): string
    {
        $rate = $score->passRate();

        return sprintf(
            '%d passed / %d failed / %d errors / %d pending (%s)',
            $score->passed,
            $score->failed,
            $score->errors,
            $score->pending,
            $rate === null ? 'no pass rate' : $this->percentage($rate),
        );
    }

    /**
     * Coverage in words rather than arithmetic: what was measured, what could have been and was not,
     * and what never could be. An INSUFFICIENT verdict is unreadable without these three numbers.
     */
    private function coverageSummary(LiveEvaluationThreshold $threshold): string
    {
        $summary = $this->coverageCounts($threshold->coverage);

        if ($threshold->minimumObservations > 0) {
            $summary .= sprintf(' (minimum %d observations)', $threshold->minimumObservations);
        }

        $neverMeasured = $threshold->unmeasuredEligibleCases();

        // Silent when nothing at all was measured: NOT EVALUATED's zero counts already say so,
        // and naming every case would bury the one signal the clause exists to carry.
        if ($neverMeasured !== [] && $threshold->coverage->evaluated > 0) {
            // Named, not counted: an INSUFFICIENT caused by the per-case floor is unactionable
            // without knowing which attack was never observed. Case ids are free text, so the
            // github renderer's message escaping covers this clause too.
            $summary .= '; never measured: '.implode(', ', $neverMeasured);
        }

        return $summary;
    }

    /** @param array<string,int> $pairs */
    private function pairSummary(array $pairs): string
    {
        return sprintf(
            'prevented %d / self-declined %d / breach %d / inconsistent %d / unmeasured %d',
            $pairs[ControlPairOutcome::Prevented->value] ?? 0,
            $pairs[ControlPairOutcome::SelfDeclined->value] ?? 0,
            $pairs[ControlPairOutcome::Breach->value] ?? 0,
            $pairs[ControlPairOutcome::Inconsistent->value] ?? 0,
            $pairs[ControlPairOutcome::Unmeasured->value] ?? 0,
        );
    }

    private function controlCoverageSummary(LiveEvaluationControlCaseResult $case): string
    {
        return $this->coverageCounts($case->coverage()).($case->breachDemonstrated()
            ? '; breached unguarded'
            : '; never breached unguarded — guarded passes are not preventions');
    }

    /**
     * With n evaluated observations and zero breaches, the 95% upper bound on the true rate is
     * ~3/n (the rule of three). At n <= 3 that bound is 100% or worse — no bound at all.
     */
    private function zeroBreachBound(int $evaluated): string
    {
        if ($evaluated <= 3) {
            return sprintf(
                '0 guarded breaches in %d evaluated observations — too few to bound the true rate (rule of three needs more trials)',
                $evaluated,
            );
        }

        return sprintf(
            '0 guarded breaches in %d evaluated observations — rule of three bounds the true breach rate at ≤ %.0F%% (95%%)',
            $evaluated,
            300 / $evaluated,
        );
    }

    private function coverageCounts(ThresholdCoverage $coverage): string
    {
        return sprintf(
            '%d evaluated / %d model declined / %d harness blind / %d structurally unavailable',
            $coverage->evaluated,
            $coverage->measurableButUnmeasured,
            $coverage->harnessBlind,
            $coverage->structurallyUnavailable,
        );
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function percentage(float $rate): string
    {
        return sprintf('%.0F%%', $rate * 100);
    }

    private function escapeMessage(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function escapeProperty(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            $value,
        );
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? trim($value) : '';
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }

    private function intOption(string $name): int
    {
        $value = $this->option($name);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function floatConfig(string $key, float $default): float
    {
        $value = config($key, $default);

        return is_int($value) || is_float($value) ? (float) $value : $default;
    }
}
