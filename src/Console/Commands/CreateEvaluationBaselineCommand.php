<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Evaluation\EvaluationReportFile;
use Illuminate\Console\Command;
use Throwable;

final class CreateEvaluationBaselineCommand extends Command
{
    protected $signature = 'verdict:evaluation-baseline
        {report : Path to a redacted Verdict evaluation report}
        {baseline : Destination for the validated baseline}
        {--force : Atomically replace an existing baseline}';

    protected $description = 'Write a validated redacted evaluation baseline';

    public function handle(EvaluationReportFile $files): int
    {
        try {
            $report = $files->report($this->stringArgument('report'));
            $files->writeBaseline(
                $this->stringArgument('baseline'),
                $report,
                (bool) $this->option('force'),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        $this->components->info('Validated Verdict evaluation baseline written.');

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? trim($value) : '';
    }
}
