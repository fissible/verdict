<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

final class BaselineComparator
{
    public function compare(EvaluationBaseline $baseline, SuiteResult $current): BaselineComparison
    {
        if ($baseline->suite !== $current->suite) {
            throw new InvalidArgumentException('An evaluation baseline can only be compared with the same suite.');
        }

        $baselineCases = $baseline->cases();
        $seen = [];
        $changes = [];

        foreach ($current->cases as $case) {
            $seen[$case->id] = true;
            $previous = $baselineCases[$case->id] ?? null;

            if ($previous === null) {
                $changes = [...$changes, ...$this->addedCaseChanges($case)];

                continue;
            }

            if ($previous['purpose'] !== $case->purpose) {
                $changes[] = new BaselineChange(
                    $case->id,
                    $previous['purpose'],
                    BaselineChangeKind::RemovedCoverage,
                    $previous['status'],
                    null,
                );
                $changes = [...$changes, ...$this->addedCaseChanges($case)];

                continue;
            }

            if ($previous['status'] === $case->status) {
                continue;
            }

            $changes[] = new BaselineChange(
                $case->id,
                $case->purpose,
                $this->changeKind($previous['status'], $case->status),
                $previous['status'],
                $case->status,
            );
        }

        foreach ($baselineCases as $id => $case) {
            if (! isset($seen[$id])) {
                $changes[] = new BaselineChange(
                    $id,
                    $case['purpose'],
                    BaselineChangeKind::RemovedCoverage,
                    $case['status'],
                    null,
                );
            }
        }

        return new BaselineComparison($changes);
    }

    private function changeKind(CaseStatus $baseline, CaseStatus $current): BaselineChangeKind
    {
        if ($current === CaseStatus::Pending) {
            return BaselineChangeKind::SuspendedCoverage;
        }

        if ($current === CaseStatus::Error) {
            return BaselineChangeKind::HarnessError;
        }

        if (in_array($baseline, [CaseStatus::Error, CaseStatus::Pending], true) && $current === CaseStatus::Passed) {
            return BaselineChangeKind::Recovered;
        }

        if (in_array($baseline, [CaseStatus::Error, CaseStatus::Pending], true)) {
            return BaselineChangeKind::BehavioralFailure;
        }

        return $current === CaseStatus::Passed
            ? BaselineChangeKind::Improvement
            : BaselineChangeKind::BehavioralRegression;
    }

    /** @return list<BaselineChange> */
    private function addedCaseChanges(CaseResult $case): array
    {
        $changes = [new BaselineChange(
            $case->id,
            $case->purpose,
            BaselineChangeKind::AddedCoverage,
            null,
            $case->status,
        )];

        if ($case->status === CaseStatus::Failed) {
            $changes[] = new BaselineChange(
                $case->id,
                $case->purpose,
                BaselineChangeKind::BehavioralFailure,
                null,
                $case->status,
            );
        }

        if ($case->status === CaseStatus::Error) {
            $changes[] = new BaselineChange(
                $case->id,
                $case->purpose,
                BaselineChangeKind::HarnessError,
                null,
                $case->status,
            );
        }

        return $changes;
    }
}
