<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evaluation\AssertionResult;
use Fissible\Verdict\Evaluation\Observation;

interface ObservationAssertion
{
    public function evaluate(Observation $observation): AssertionResult;
}
