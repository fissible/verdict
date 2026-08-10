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
            if (self::isBlocking($change->kind)) {
                return true;
            }
        }

        return false;
    }

    public static function isBlocking(BaselineChangeKind $kind): bool
    {
        return in_array($kind, [
            BaselineChangeKind::BehavioralRegression,
            BaselineChangeKind::BehavioralFailure,
            BaselineChangeKind::HarnessError,
            BaselineChangeKind::RemovedCoverage,
            BaselineChangeKind::SuspendedCoverage,
        ], true);
    }
}
