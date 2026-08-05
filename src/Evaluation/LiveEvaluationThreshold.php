<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class LiveEvaluationThreshold
{
    public function __construct(
        public CasePurpose $purpose,
        public float $minimumPassRate,
        public Score $score,
    ) {}

    public function disposition(): LiveEvaluationThresholdDisposition
    {
        $passRate = $this->score->passRate();

        if ($passRate === null) {
            return LiveEvaluationThresholdDisposition::NotEvaluated;
        }

        return $passRate >= $this->minimumPassRate
            ? LiveEvaluationThresholdDisposition::Met
            : LiveEvaluationThresholdDisposition::NotMet;
    }
}
