<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class LiveEvaluationCaseResult
{
    /**
     * `$errorBreakdown` is sparse: a category absent from it occurred zero times for this case,
     * not an unreported or unclassified outcome.
     *
     * @param  array<string,int>  $errorBreakdown
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
