<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

final readonly class EvaluationReportFile
{
    public function __construct(private Filesystem $files) {}

    public function report(string $path): EvaluationReport
    {
        $contents = $this->read($path, 'evaluation report');

        try {
            return EvaluationReport::fromJson($contents);
        } catch (Throwable $exception) {
            throw new RuntimeException('The evaluation report is malformed or unsupported.', 0, $exception);
        }
    }

    public function baseline(string $path): EvaluationBaseline
    {
        $contents = $this->read($path, 'evaluation baseline');

        try {
            return EvaluationBaseline::fromJson($contents);
        } catch (Throwable $exception) {
            throw new RuntimeException('The evaluation baseline is malformed or unsupported.', 0, $exception);
        }
    }

    public function writeBaseline(string $path, EvaluationReport $report, bool $force): void
    {
        if ($this->files->isDirectory($path)) {
            throw new RuntimeException('The evaluation baseline path points to a directory.');
        }

        if ($this->files->exists($path) && ! $force) {
            throw new RuntimeException('The evaluation baseline already exists; repeat with --force to replace it.');
        }

        $directory = dirname($path);

        if (! $this->files->isDirectory($directory) || ! $this->files->isWritable($directory)) {
            throw new RuntimeException('The evaluation baseline directory is missing or not writable.');
        }

        $contents = $report->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

        EvaluationBaseline::fromJson($contents);
        $this->files->replace($path, $contents);

        if (! $this->files->isFile($path) || $this->files->get($path) !== $contents) {
            throw new RuntimeException('The evaluation baseline could not be written safely.');
        }
    }

    private function read(string $path, string $label): string
    {
        if (! $this->files->isFile($path) || ! $this->files->isReadable($path)) {
            throw new RuntimeException("The {$label} is missing or unreadable.");
        }

        return $this->files->get($path);
    }
}
