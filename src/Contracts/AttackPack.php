<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Closure;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\EvaluationCase;
use Fissible\Verdict\Evaluation\Observation;

interface AttackPack
{
    /**
     * @param  Closure(CaseInput): Observation  $runner
     * @return list<EvaluationCase>
     */
    public function cases(Closure $runner): array;
}
