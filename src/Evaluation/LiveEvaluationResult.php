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
    ) {}

    public function report(): LiveEvaluationReport
    {
        return new LiveEvaluationReport($this);
    }
}
