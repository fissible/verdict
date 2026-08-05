<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

enum LiveEvaluationThresholdDisposition: string
{
    case Met = 'met';
    case NotMet = 'not_met';
    case NotEvaluated = 'not_evaluated';
}
