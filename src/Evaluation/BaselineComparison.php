<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class BaselineComparison
{
    /** @param list<BaselineChange> $changes */
    public function __construct(public array $changes) {}

    public function hasBlockingChanges(): bool
    {
        foreach ($this->changes as $change) {
            if (in_array($change->kind, [
                BaselineChangeKind::BehavioralRegression,
                BaselineChangeKind::BehavioralFailure,
                BaselineChangeKind::HarnessError,
                BaselineChangeKind::RemovedCoverage,
            ], true)) {
                return true;
            }
        }

        return false;
    }
}
