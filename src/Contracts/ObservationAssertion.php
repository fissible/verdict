<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evaluation\AssertionResult;
use Fissible\Verdict\Evaluation\Observation;

/**
 * @experimental The evaluation assertion and reporting surface may change before Verdict 1.0.
 */
interface ObservationAssertion
{
    public function evaluate(Observation $observation): AssertionResult;
}
