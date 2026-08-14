<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Contracts\LiveEvaluationSuiteFactory;
use Fissible\Verdict\Evaluation\LiveEvaluationOptions;
use Fissible\Verdict\Evaluation\LiveEvaluationResult;
use Fissible\Verdict\Evaluation\LiveEvaluationRunner;
use Fissible\Verdict\Evaluation\LiveEvaluationThreshold;
use Fissible\Verdict\Evaluation\LiveEvaluationThresholdDisposition;
use Fissible\Verdict\Evaluation\Score;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Throwable;

final class RunLiveEvaluationCommand extends Command
{
    protected $signature = 'verdict:evaluation-live
        {suite : Name of a suite configured in verdict.evaluation.suites}
        {--trials=1 : Number of trials per case, bounded by verdict.evaluation.maximum_trials}
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
            $this->components->twoColumnDetail(
                "{$case->id} ({$case->purpose->value})",
                $this->scoreSummary($case->score),
            );
        }

        $breakdown = $result->errorBreakdown();

        if ($breakdown === []) {
            return;
        }

        $this->newLine();
        // This map is sparse: a category absent here had zero occurrences. It is not reported as
        // 0 — absence, not a zero count, is what "the harness saw none of these" looks like.
        $this->components->info('Error breakdown (categories not listed below occurred zero times)');

        foreach ($breakdown as $category => $count) {
            $this->components->twoColumnDetail($category, (string) $count);
        }
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
    }

    private function renderGithub(LiveEvaluationResult $result): void
    {
        foreach ([$result->securityThreshold, $result->utilityThreshold] as $threshold) {
            $level = $threshold->disposition() === LiveEvaluationThresholdDisposition::Met ? 'notice' : 'error';
            $title = $this->escapeProperty("Verdict live evaluation: {$threshold->purpose->value}");
            $message = $this->escapeMessage(sprintf(
                '%s — %s (minimum %s)',
                $this->dispositionLabel($threshold->disposition()),
                $this->scoreSummary($threshold->score),
                $this->percentage($threshold->minimumPassRate),
            ));

            $this->line("::{$level} title={$title}::{$message}");
        }

        foreach ($result->errorBreakdown() as $category => $count) {
            $title = $this->escapeProperty('Verdict live evaluation error breakdown');
            $message = $this->escapeMessage("{$category}={$count}");

            $this->line("::notice title={$title}::{$message}");
        }
    }

    private function dispositionLabel(LiveEvaluationThresholdDisposition $disposition): string
    {
        return match ($disposition) {
            LiveEvaluationThresholdDisposition::Met => 'MET',
            LiveEvaluationThresholdDisposition::NotMet => 'NOT MET',
            LiveEvaluationThresholdDisposition::NotEvaluated => 'NOT EVALUATED',
        };
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
