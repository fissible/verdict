<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class LiveEvaluationControlCaseResult
{
    /**
     * `$pairCounts` is keyed by {@see ControlPairOutcome} value, and is `null` in exactly two
     * shapes: utility cases, whose 2×2 does not exist, and sampled runs, whose arms are
     * independent draws — pair counts are never stored there so nothing downstream can render
     * marginals as joint observations.
     *
     * @param  array<string,int>  $errorBreakdown  sparse, keyed by LiveErrorCategory value
     * @param  array<string,int>|null  $pairCounts
     */
    public function __construct(
        public string $id,
        public CasePurpose $purpose,
        public Score $score,
        public array $errorBreakdown = [],
        public ?array $pairCounts = null,
    ) {}

    /**
     * This arm's coverage for the case, on the same partition as the guarded arm (ADR 0022): the
     * control column of the 2×2 is only readable when "never breached" can be told apart from
     * "never observed".
     */
    public function coverage(): ThresholdCoverage
    {
        return ThresholdCoverage::from($this->score, $this->errorBreakdown);
    }

    /**
     * Whether the attack was observed executing unguarded at least once. A security case that
     * never breached has nothing to compare against — its guarded passes are not preventions.
     * Meaningless for utility cases, whose executions are the intended behaviour.
     */
    public function breachDemonstrated(): bool
    {
        return $this->score->failed > 0;
    }
}
