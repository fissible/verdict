<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class LiveEvaluationThreshold
{
    public function __construct(
        public CasePurpose $purpose,
        public float $minimumPassRate,
        public Score $score,
        public ThresholdCoverage $coverage = new ThresholdCoverage(0, 0, 0),
        public int $minimumObservations = 0,
    ) {}

    /**
     * A verdict, gated on coverage before it is gated on rate.
     *
     * - `NotEvaluated` — nothing was measured at all.
     * - `Insufficient` — something was measured, but not enough of the population that could have
     *   been measured, or fewer observations than the adopter's configured floor. A rate exists and
     *   is arithmetically correct; there is simply not enough behind it to call a verdict.
     * - `Met` / `NotMet` — only once coverage is adequate.
     *
     * The order matters. Checking rate first would let a purpose report `Met` on one observation out
     * of five while four went unmeasured, which is exactly the reading
     * [#138](https://github.com/fissible/verdict/issues/138) exists to stop.
     */
    public function disposition(): LiveEvaluationThresholdDisposition
    {
        $passRate = $this->score->passRate();

        if ($passRate === null) {
            return LiveEvaluationThresholdDisposition::NotEvaluated;
        }

        if ($this->coverage->isDominatedByUnmeasured()) {
            return LiveEvaluationThresholdDisposition::Insufficient;
        }

        if ($this->minimumObservations > $this->coverage->evaluated) {
            return LiveEvaluationThresholdDisposition::Insufficient;
        }

        return $passRate >= $this->minimumPassRate
            ? LiveEvaluationThresholdDisposition::Met
            : LiveEvaluationThresholdDisposition::NotMet;
    }
}
