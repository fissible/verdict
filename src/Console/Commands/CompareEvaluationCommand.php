<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Evaluation\BaselineChange;
use Fissible\Verdict\Evaluation\BaselineChangeKind;
use Fissible\Verdict\Evaluation\BaselineComparison;
use Fissible\Verdict\Evaluation\EvaluationReportFile;
use Illuminate\Console\Command;
use Throwable;

final class CompareEvaluationCommand extends Command
{
    /** @var list<BaselineChangeKind> */
    private const array DISPLAY_ORDER = [
        BaselineChangeKind::BehavioralRegression,
        BaselineChangeKind::BehavioralFailure,
        BaselineChangeKind::HarnessError,
        BaselineChangeKind::RemovedCoverage,
        BaselineChangeKind::SuspendedCoverage,
        BaselineChangeKind::Improvement,
        BaselineChangeKind::Recovered,
        BaselineChangeKind::AddedCoverage,
    ];

    /** @var array<string, string> */
    private const array LABELS = [
        'behavioral_regression' => 'Behavioral regressions',
        'behavioral_failure' => 'New behavioral failures',
        'harness_error' => 'Harness errors',
        'removed_coverage' => 'Removed coverage',
        'suspended_coverage' => 'Suspended coverage',
        'improvement' => 'Improvements',
        'recovered' => 'Recoveries',
        'added_coverage' => 'Added coverage',
    ];

    protected $signature = 'verdict:evaluation-compare
        {current : Path to the current redacted Verdict evaluation report}
        {baseline : Path to the validated evaluation baseline}
        {--format=console : Output format: console or github}';

    protected $description = 'Compare a current evaluation report with a checked-in baseline';

    public function handle(EvaluationReportFile $files): int
    {
        $format = $this->stringOption('format');

        if (! in_array($format, ['console', 'github'], true)) {
            $this->components->error('The --format option must be [console] or [github].');

            return self::INVALID;
        }

        try {
            $current = $files->report($this->stringArgument('current'))->result();
            $comparison = $current->compareTo($files->baseline($this->stringArgument('baseline')));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        if ($format === 'github') {
            $this->renderGithub($comparison);
        } else {
            $this->renderConsole($comparison);
        }

        return $comparison->hasBlockingChanges() ? self::FAILURE : self::SUCCESS;
    }

    private function renderConsole(BaselineComparison $comparison): void
    {
        if ($comparison->changes === []) {
            $this->components->info('No evaluation baseline changes detected.');

            return;
        }

        foreach (self::DISPLAY_ORDER as $kind) {
            $changes = array_values(array_filter(
                $comparison->changes,
                static fn (BaselineChange $change): bool => $change->kind === $kind,
            ));

            if ($changes === []) {
                continue;
            }

            $this->newLine();
            $this->components->info(self::LABELS[$kind->value]);

            foreach ($changes as $change) {
                $this->components->twoColumnDetail(
                    $change->caseId,
                    $this->statusTransition($change),
                );
            }
        }
    }

    private function renderGithub(BaselineComparison $comparison): void
    {
        if ($comparison->changes === []) {
            $this->line('::notice title=Verdict evaluation::No evaluation baseline changes detected.');

            return;
        }

        foreach ($comparison->changes as $change) {
            $level = BaselineComparison::isBlocking($change->kind) ? 'error' : 'notice';
            $title = $this->escapeProperty('Verdict '.self::LABELS[$change->kind->value]);
            $message = $this->escapeMessage(
                "case_id={$change->caseId} category={$change->kind->value} {$this->statusTransition($change)}",
            );

            $this->line("::{$level} title={$title}::{$message}");
        }
    }

    private function statusTransition(BaselineChange $change): string
    {
        $baseline = $change->baselineStatus === null ? 'missing' : $change->baselineStatus->value;
        $current = $change->currentStatus === null ? 'missing' : $change->currentStatus->value;

        return "{$baseline} -> {$current}";
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
}
