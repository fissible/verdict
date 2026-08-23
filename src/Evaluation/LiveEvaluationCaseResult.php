<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class LiveEvaluationCaseResult
{
    /**
     * `$errorBreakdown` is sparse: a category absent from it occurred zero times for this case,
     * not an unreported or unclassified outcome.
     *
     * `$overRestricted` counts Failed trials of a filtered-permit case where only utility-facet
     * assertions failed — the guard held, the model under-delivered (#276). They are included in
     * `$score->passed`, never in `failed`. `$failedAssertions` is sparse: assertion name to the
     * number of trials it failed in, across every Failed trial (over-restricted ones included).
     *
     * @param  array<string,int>  $errorBreakdown
     * @param  array<string,int>  $failedAssertions
     */
    public function __construct(
        public string $id,
        public string $version,
        public CasePurpose $purpose,
        public string $trustedSetupFingerprint,
        public string $untrustedInputFingerprint,
        public Score $score,
        public array $errorBreakdown = [],
        public SafeOutcome $safeOutcome = SafeOutcome::Blocked,
        public int $overRestricted = 0,
        public array $failedAssertions = [],
    ) {}

    /**
     * This case's coverage, using the same measurable / structural partition as the purpose
     * level — the purpose's coverage is exactly the sum of its cases'.
     */
    public function coverage(): ThresholdCoverage
    {
        return ThresholdCoverage::from($this->score, $this->errorBreakdown);
    }
}
