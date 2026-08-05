<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final class LiveEvaluationScoreCounter
{
    private int $passed = 0;

    private int $failed = 0;

    private int $errors = 0;

    public function record(CaseStatus $status): void
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
        }
    }

    public function score(): Score
    {
        return new Score($this->passed, $this->failed, $this->errors);
    }
}
