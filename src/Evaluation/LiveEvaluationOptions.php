<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

final readonly class LiveEvaluationOptions
{
    public function __construct(
        public int $trials,
        public float $minimumSecurityPassRate,
        public float $minimumUtilityPassRate,
        public bool $enabled = false,
        /**
         * An adopter-controlled absolute floor on evaluated observations per purpose. Zero disables
         * it; the coverage rule in LiveEvaluationThreshold applies either way. This is the
         * sample-size policy — how many observations you consider enough — as distinct from
         * coverage adequacy, which asks how much of the measurable population was measured.
         */
        public int $minimumObservations = 0,
        /**
         * Run the unguarded control arm alongside the guarded one. Requires its own configuration
         * gate (verdict.evaluation.control_enabled) in addition to the two live-evaluation gates,
         * and a factory implementing LiveEvaluationControlArmFactory. See ADR 0023.
         */
        public bool $controlArm = false,
    ) {
        if ($this->trials < 1) {
            throw new InvalidArgumentException('Live evaluation trials must be a positive integer.');
        }

        if ($this->minimumObservations < 0) {
            throw new InvalidArgumentException('The minimum live evaluation observations must not be negative.');
        }

        $this->assertPassRate($this->minimumSecurityPassRate, 'security');
        $this->assertPassRate($this->minimumUtilityPassRate, 'utility');
    }

    private function assertPassRate(float $passRate, string $purpose): void
    {
        if ($passRate < 0 || $passRate > 1) {
            throw new InvalidArgumentException("The minimum {$purpose} pass rate must be between 0 and 1.");
        }
    }
}
