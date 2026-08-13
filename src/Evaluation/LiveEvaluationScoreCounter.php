<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final class LiveEvaluationScoreCounter
{
    private int $passed = 0;

    private int $failed = 0;

    private int $errors = 0;

    private int $pending = 0;

    /** @var array<string,int> */
    private array $errorBreakdown = [];

    public function record(CaseStatus $status, ?string $errorClass = null): void
    {
        switch ($status) {
            case CaseStatus::Passed:
                $this->passed++;

                return;
            case CaseStatus::Failed:
                $this->failed++;

                return;
            case CaseStatus::Error:
                $this->errors++;
                $category = LiveErrorCategory::fromErrorClass($errorClass) ?? LiveErrorCategory::Uncategorized;
                $this->errorBreakdown[$category->value] = ($this->errorBreakdown[$category->value] ?? 0) + 1;

                return;
            case CaseStatus::Pending:
                $this->pending++;
        }
    }

    public function score(): Score
    {
        return new Score($this->passed, $this->failed, $this->errors, $this->pending);
    }

    /** @return array<string,int> */
    public function errorBreakdown(): array
    {
        return $this->errorBreakdown;
    }
}
