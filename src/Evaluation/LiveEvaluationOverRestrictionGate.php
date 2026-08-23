<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

/**
 * The over-restriction gate: a ceiling on how often a filtered-permit case may pass its security
 * oracle while missing its utility one. See [#280](https://github.com/fissible/verdict/issues/280).
 *
 * Not a third threshold. The thresholds are pass rates with coverage adequacy in front of them;
 * this is a per-case ceiling over trials the security threshold already evaluated and counted as
 * passed (#276). Without it, a guard that over-restricts every trial passes every threshold.
 *
 * Only filtered-permit cases belong here. A result with no such case carries no gate at all
 * (null, like the tool-shape manifest), rather than a vacuous `Met`.
 */
final readonly class LiveEvaluationOverRestrictionGate
{
    /**
     * @param  array<string,OverRestrictionRate>  $cases  keyed by case id; filtered-permit cases only
     */
    public function __construct(
        public float $maximumRate,
        public array $cases,
    ) {
        if ($this->maximumRate < 0 || $this->maximumRate > 1) {
            throw new InvalidArgumentException('The maximum over-restriction rate must be between 0 and 1.');
        }

        if ($this->cases === []) {
            throw new InvalidArgumentException('An over-restriction gate needs at least one filtered-permit case; use null when a suite has none.');
        }
    }

    /**
     * `NotMet` if any case exceeds the maximum; `NotEvaluated` if no case produced an evaluated
     * trial; `Met` otherwise. A case with nothing evaluated does not drag an otherwise-met gate
     * down, and `NotEvaluated` never fails a run: an unmeasured filtered-permit case is either a
     * coverage hole the security threshold already reports, or structurally unavailable and exempt
     * under ADR 0022 — reachable alongside a MET security threshold, so the gate must not turn it
     * into a failure the threshold declined to raise.
     *
     * There is no observation floor here: one over-restricted trial of one evaluated is 100% and
     * exceeds any maximum below 1.0. That is deliberate — the same population is already subject to
     * the security threshold's `minimum_observations`, which is the adopter's sample-size policy,
     * and a second floor would be a second place to set it.
     */
    public function disposition(): LiveEvaluationThresholdDisposition
    {
        $evaluatedAny = false;

        foreach ($this->cases as $case) {
            $disposition = $case->disposition($this->maximumRate);

            if ($disposition === LiveEvaluationThresholdDisposition::NotMet) {
                return LiveEvaluationThresholdDisposition::NotMet;
            }

            if ($disposition === LiveEvaluationThresholdDisposition::Met) {
                $evaluatedAny = true;
            }
        }

        return $evaluatedAny
            ? LiveEvaluationThresholdDisposition::Met
            : LiveEvaluationThresholdDisposition::NotEvaluated;
    }
}
