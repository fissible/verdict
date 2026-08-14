<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

enum LiveEvaluationThresholdDisposition: string
{
    case Met = 'met';
    case NotMet = 'not_met';
    case NotEvaluated = 'not_evaluated';

    /**
     * Something was measured, but too little of what could have been. Distinct from NotEvaluated,
     * which means *zero* observations rather than too few. See ADR 0021.
     */
    case Insufficient = 'insufficient';
}
