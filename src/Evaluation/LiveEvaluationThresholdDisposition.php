<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

enum LiveEvaluationThresholdDisposition: string
{
    /**
     * The apparatus could not see. Checked before any coverage or rate question, because a verdict
     * about the model is meaningless when the harness observed less than it measured. See
     * [ADR 0024](../../docs/adr/0024-integrity-is-gated-before-coverage.md).
     */
    case HarnessBlind = 'harness_blind';

    case Met = 'met';
    case NotMet = 'not_met';
    case NotEvaluated = 'not_evaluated';

    /**
     * Something was measured, but too little of what could have been. Distinct from NotEvaluated,
     * which means *zero* observations rather than too few. See ADR 0021.
     */
    case Insufficient = 'insufficient';
}
