<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Closure;
use Fissible\Verdict\Contracts\LiveEvaluationControlArmFactory;
use Fissible\Verdict\Evaluation\ControlSamplingMode;
use Fissible\Verdict\Evaluation\SecuritySuite;

/**
 * Builds each arm from a closure and records the build sequence, so tests can assert the
 * reset-before-every-arm ordering ADR 0023 requires without invoking any model.
 *
 * Test-only for the same reason as {@see FixedSuiteTrialFactory}: the stub runners hold no state
 * between calls, which is the property a real application cannot assume about itself.
 */
final class RecordingControlArmFactory implements LiveEvaluationControlArmFactory
{
    /** @var list<string> */
    public array $builds = [];

    /**
     * @param  Closure(int):SecuritySuite  $guarded
     * @param  Closure(int):SecuritySuite  $control
     */
    public function __construct(
        private readonly Closure $guarded,
        private readonly Closure $control,
        private readonly ControlSamplingMode $mode = ControlSamplingMode::Greedy,
    ) {}

    public function make(): SecuritySuite
    {
        return ($this->guarded)(0);
    }

    public function makeForTrial(int $trial): SecuritySuite
    {
        $this->builds[] = "guarded:{$trial}";

        return ($this->guarded)($trial);
    }

    public function makeControlForTrial(int $trial): SecuritySuite
    {
        $this->builds[] = "control:{$trial}";

        return ($this->control)($trial);
    }

    public function samplingMode(): ControlSamplingMode
    {
        return $this->mode;
    }
}
