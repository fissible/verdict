<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

enum BaselineChangeKind: string
{
    case BehavioralRegression = 'behavioral_regression';
    case BehavioralFailure = 'behavioral_failure';
    case Improvement = 'improvement';
    case HarnessError = 'harness_error';
    case Recovered = 'recovered';
    case AddedCoverage = 'added_coverage';
    case RemovedCoverage = 'removed_coverage';
    case SuspendedCoverage = 'suspended_coverage';
}
