<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final class LiveEvaluationScoreCounter
{
    private int $passed = 0;

    private int $failed = 0;

    private int $errors = 0;

    private int $pending = 0;

    private int $overRestricted = 0;

    /** @var array<string,int> */
    private array $errorBreakdown = [];

    /** @var array<string,int> */
    private array $failedAssertions = [];

    /**
     * `$assertions` is the case's assertion list (passed ones are ignored). A Failed trial of a
     * filtered-permit case whose failing assertions are all utility-facet is over-restricted
     * (#251 round 5, #276): the security-facet oracle held — no forbidden row, predicate observed
     * and declared — and only the identity/utility side did not. That counts as passed for the
     * security score, with its own tally, so it neither reads as a breach nor suppresses the
     * zero-breach bound. A blocked-outcome case never takes this path: its security assertions
     * ARE the case, and a utility-facet failure there has no over-restricted reading.
     *
     * @param  list<AssertionResult>  $assertions
     */
    public function record(
        CaseStatus $status,
        ?string $errorClass,
        array $assertions,
        SafeOutcome $safeOutcome,
    ): void {
        switch ($status) {
            case CaseStatus::Passed:
                $this->passed++;

                return;
            case CaseStatus::Failed:
                $failedFacets = [];

                foreach ($assertions as $assertion) {
                    if ($assertion->passed) {
                        continue;
                    }

                    $this->failedAssertions[$assertion->assertion] = ($this->failedAssertions[$assertion->assertion] ?? 0) + 1;
                    $failedFacets[$assertion->facet->value] = true;
                }

                if ($safeOutcome === SafeOutcome::FilteredPermit && $failedFacets === [AssertionFacet::Utility->value => true]) {
                    $this->overRestricted++;
                    $this->passed++;

                    return;
                }

                $this->failed++;

                return;
            case CaseStatus::Error:
                $this->errors++;
                $category = LiveErrorCategory::fromErrorClass($errorClass) ?? LiveErrorCategory::Uncategorized;
                $this->errorBreakdown[$category->value] = ($this->errorBreakdown[$category->value] ?? 0) + 1;

                return;
            case CaseStatus::Pending:
                $this->pending++;
        }
    }

    public function score(): Score
    {
        return new Score($this->passed, $this->failed, $this->errors, $this->pending);
    }

    /** @return array<string,int> */
    public function errorBreakdown(): array
    {
        return $this->errorBreakdown;
    }

    /** Failed trials of a filtered-permit case where only utility-facet assertions failed. */
    public function overRestricted(): int
    {
        return $this->overRestricted;
    }

    /**
     * Sparse: assertion name to the number of trials it failed in. Retained so a failed case can
     * be attributed from the run's own output instead of an isolated re-run (#276).
     *
     * @return array<string,int>
     */
    public function failedAssertions(): array
    {
        return $this->failedAssertions;
    }
}
