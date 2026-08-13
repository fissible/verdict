<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evaluation\SecuritySuite;

/** Creates a configured SecuritySuite for live evaluation. */
interface LiveEvaluationSuiteFactory
{
    public function make(): SecuritySuite;
}
