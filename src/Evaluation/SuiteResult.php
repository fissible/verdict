<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use DateTimeImmutable;

final readonly class SuiteResult
{
    /**
     * @param  list<CaseResult>  $cases
     */
    public function __construct(
        public string $suite,
        public string $version,
        public ReproductionMetadata $reproduction,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $completedAt,
        public array $cases,
        /** @var list<ToolShape>|null the pack's coverage manifest; null when none was declared */
        public ?array $toolShapes = null,
    ) {}

    public function score(CasePurpose $purpose): Score
    {
        $passed = 0;
        $failed = 0;
        $errors = 0;
        $pending = 0;

        foreach ($this->cases as $case) {
            if ($case->purpose !== $purpose) {
                continue;
            }

            match ($case->status) {
                CaseStatus::Passed => $passed++,
                CaseStatus::Failed => $failed++,
                CaseStatus::Error => $errors++,
                CaseStatus::Pending => $pending++,
            };
        }

        return new Score($passed, $failed, $errors, $pending);
    }

    public function passed(): bool
    {
        foreach ($this->cases as $case) {
            if ($case->status !== CaseStatus::Passed) {
                return false;
            }
        }

        return true;
    }

    public function report(): EvaluationReport
    {
        return new EvaluationReport($this);
    }

    public function compareTo(EvaluationBaseline $baseline): BaselineComparison
    {
        return (new BaselineComparator)->compare($baseline, $this);
    }
}
