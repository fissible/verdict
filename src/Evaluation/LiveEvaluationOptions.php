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
    ) {
        if ($this->trials < 1) {
            throw new InvalidArgumentException('Live evaluation trials must be a positive integer.');
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
