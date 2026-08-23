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
        /** @var list<ToolShape>|null the pack's coverage manifest; null when none was declared */
        public ?array $toolShapes = null,
        /**
         * The trial after which the run stopped because the harness was systematically blind, or
         * null if it ran to completion. `$trials` is the number actually run, not the number
         * requested — a halted run must not report a trial count it did not reach.
         * See [ADR 0024](../../docs/adr/0024-integrity-is-gated-before-coverage.md).
         */
        public ?int $haltedAfterTrial = null,
        /**
         * The over-restriction gate (#280). Null when the suite has no filtered-permit case —
         * absent, not a vacuous `Met` — so a blocked-outcome-only suite shows no gate at all.
         */
        public ?LiveEvaluationOverRestrictionGate $overRestriction = null,
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
