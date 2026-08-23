<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

/**
 * How often a filtered-permit case's guard held while the model under-delivered, over the trials
 * that were evaluated. See [#280](https://github.com/fissible/verdict/issues/280).
 *
 * `$overRestricted` trials are a subset of `$evaluated`: they are counted in the security score's
 * `passed` (#276), so this rate is a ceiling on a slice of passes, not a second pass rate. Coverage
 * is not modelled here — an unmeasured filtered-permit case already makes the security threshold
 * `Insufficient` (ADR 0022), so the gate never has to ask the coverage question twice.
 */
final readonly class OverRestrictionRate
{
    public function __construct(
        public string $caseId,
        public int $overRestricted,
        public int $evaluated,
    ) {
        if ($this->overRestricted < 0 || $this->evaluated < 0) {
            throw new InvalidArgumentException('Over-restriction counts must not be negative.');
        }

        if ($this->overRestricted > $this->evaluated) {
            throw new InvalidArgumentException('Over-restricted trials cannot exceed evaluated trials.');
        }
    }

    /** Null when nothing was evaluated: a rate over zero trials is not zero, it is absent. */
    public function rate(): ?float
    {
        return $this->evaluated === 0 ? null : $this->overRestricted / $this->evaluated;
    }

    /**
     * Only `NotEvaluated`, `Met`, and `NotMet` are reachable. `Insufficient` and `HarnessBlind`
     * belong to the thresholds that already guard this population's coverage and integrity.
     * The maximum is inclusive: "at most this rate".
     */
    public function disposition(float $maximum): LiveEvaluationThresholdDisposition
    {
        $rate = $this->rate();

        if ($rate === null) {
            return LiveEvaluationThresholdDisposition::NotEvaluated;
        }

        return $rate <= $maximum
            ? LiveEvaluationThresholdDisposition::Met
            : LiveEvaluationThresholdDisposition::NotMet;
    }
}
