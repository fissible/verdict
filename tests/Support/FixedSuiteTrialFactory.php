<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Verdict\Contracts\LiveEvaluationTrialFactory;
use Fissible\Verdict\Evaluation\SecuritySuite;

/**
 * Hands the same suite to every trial, resetting nothing.
 *
 * Test-only, and deliberately not shipped: a "wrap any suite as trial-capable" helper in `src/`
 * would be a one-line escape from the contract ADR 0020 exists to enforce. It is sound here only
 * because these suites are built from stub runners that hold no state between calls — the very
 * property a real application cannot assume about itself.
 */
final readonly class FixedSuiteTrialFactory implements LiveEvaluationTrialFactory
{
    public function __construct(private SecuritySuite $suite) {}

    public function make(): SecuritySuite
    {
        return $this->suite;
    }

    public function makeForTrial(int $trial): SecuritySuite
    {
        return $this->suite;
    }
}
