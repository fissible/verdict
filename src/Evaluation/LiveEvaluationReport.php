<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use JsonException;
use JsonSerializable;

final readonly class LiveEvaluationReport implements JsonSerializable
{
    public const string SCHEMA = 'verdict.live-evaluation-report.v1';

    public function __construct(private LiveEvaluationResult $result) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $report = [
            'schema' => self::SCHEMA,
            'suite' => $this->result->suite,
            'version' => $this->result->version,
            'trials' => $this->result->trials,
            'started_at' => $this->result->startedAt->format(DATE_ATOM),
            'completed_at' => $this->result->completedAt->format(DATE_ATOM),
            'reproduction' => $this->result->reproduction->components,
            'thresholds' => [
                CasePurpose::Security->value => $this->thresholdArray($this->result->securityThreshold),
                CasePurpose::Utility->value => $this->thresholdArray($this->result->utilityThreshold),
            ],
            'cases' => array_map($this->caseArray(...), $this->result->cases),
        ];

        // Additive to the v1 schema, absent on non-control runs. `pairs` is null under sampled
        // decoding by construction — the runner never stores pair counts there. See ADR 0023.
        if ($this->result->control !== null) {
            $report['control'] = [
                'sampling_mode' => $this->result->control->samplingMode->value,
                'cases' => array_map($this->controlCaseArray(...), $this->result->control->cases),
            ];
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function controlCaseArray(LiveEvaluationControlCaseResult $case): array
    {
        $coverage = $case->coverage();

        return [
            'id' => $case->id,
            'purpose' => $case->purpose->value,
            'safe_outcome' => $case->safeOutcome->value,
            'score' => $this->scoreArray($case->score),
            'coverage' => [
                'evaluated' => $coverage->evaluated,
                'measurable_but_unmeasured' => $coverage->measurableButUnmeasured,
                'structurally_unavailable' => $coverage->structurallyUnavailable,
            ],
            'pairs' => $case->pairCounts,
            // Null for utility cases: executing is their intended behaviour, not a breach.
            'breach_demonstrated' => $case->purpose === CasePurpose::Security ? $case->breachDemonstrated() : null,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @throws JsonException */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this, $flags | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, float|int|string|null> */
    private function thresholdArray(LiveEvaluationThreshold $threshold): array
    {
        return [
            'minimum_pass_rate' => $threshold->minimumPassRate,
            'disposition' => $threshold->disposition()->value,
            ...$this->scoreArray($threshold->score),
        ];
    }

    /** @return array<string, int|float|null> */
    private function scoreArray(Score $score): array
    {
        return [
            'passed' => $score->passed,
            'failed' => $score->failed,
            'errors' => $score->errors,
            'pending' => $score->pending,
            'evaluated' => $score->evaluated(),
            'total' => $score->total(),
            'pass_rate' => $score->passRate(),
        ];
    }

    /** @return array<string, mixed> */
    private function caseArray(LiveEvaluationCaseResult $case): array
    {
        $coverage = $case->coverage();

        return [
            'id' => $case->id,
            'version' => $case->version,
            'purpose' => $case->purpose->value,
            'safe_outcome' => $case->safeOutcome->value,
            'trusted_setup_fingerprint' => $case->trustedSetupFingerprint,
            'untrusted_input_fingerprint' => $case->untrustedInputFingerprint,
            'score' => $this->scoreArray($case->score),
            // Additive to the v1 schema. The purpose-level coverage is exactly the sum of these,
            // so a reader can see which case a verdict's support came from without arithmetic.
            'coverage' => [
                'evaluated' => $coverage->evaluated,
                'measurable_but_unmeasured' => $coverage->measurableButUnmeasured,
                'structurally_unavailable' => $coverage->structurallyUnavailable,
            ],
        ];
    }
}
