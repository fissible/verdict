<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use DateTimeImmutable;

final readonly class LiveEvaluationResult
{
    /**
     * @param  list<LiveEvaluationCaseResult>  $cases
     */
    public function __construct(
        public string $suite,
        public string $version,
        public ReproductionMetadata $reproduction,
        public int $trials,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $completedAt,
        public array $cases,
        public LiveEvaluationThreshold $securityThreshold,
        public LiveEvaluationThreshold $utilityThreshold,
        /** Present only on control-arm runs; the guarded arm's thresholds above are unaffected by it. */
        public ?LiveEvaluationControlResult $control = null,
    ) {}

    public function report(): LiveEvaluationReport
    {
        return new LiveEvaluationReport($this);
    }

    /**
     * The map is sparse: a category absent from it occurred zero times, not an unreported or
     * unclassified outcome.
     *
     * @return array<string,int>
     */
    public function errorBreakdown(): array
    {
        $breakdown = [];

        foreach ($this->cases as $case) {
            foreach ($case->errorBreakdown as $category => $count) {
                $breakdown[$category] = ($breakdown[$category] ?? 0) + $count;
            }
        }

        return $breakdown;
    }
}
