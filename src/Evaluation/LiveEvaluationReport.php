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
        return [
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
            'evaluated' => $score->evaluated(),
            'total' => $score->total(),
            'pass_rate' => $score->passRate(),
        ];
    }

    /** @return array<string, mixed> */
    private function caseArray(LiveEvaluationCaseResult $case): array
    {
        return [
            'id' => $case->id,
            'version' => $case->version,
            'purpose' => $case->purpose->value,
            'trusted_setup_fingerprint' => $case->trustedSetupFingerprint,
            'untrusted_input_fingerprint' => $case->untrustedInputFingerprint,
            'score' => $this->scoreArray($case->score),
        ];
    }
}
