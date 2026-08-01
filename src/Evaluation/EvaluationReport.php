<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use JsonSerializable;

final readonly class EvaluationReport implements JsonSerializable
{
    public const string SCHEMA = 'verdict.evaluation-report.v1';

    public function __construct(private SuiteResult $result) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'suite' => $this->result->suite,
            'version' => $this->result->version,
            'passed' => $this->result->passed(),
            'started_at' => $this->result->startedAt->format(DATE_ATOM),
            'completed_at' => $this->result->completedAt->format(DATE_ATOM),
            'reproduction' => $this->result->reproduction->components,
            'scores' => [
                CasePurpose::Security->value => $this->scoreArray(
                    $this->result->score(CasePurpose::Security),
                ),
                CasePurpose::Utility->value => $this->scoreArray(
                    $this->result->score(CasePurpose::Utility),
                ),
            ],
            'cases' => array_map($this->caseArray(...), $this->result->cases),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this, $flags | JSON_THROW_ON_ERROR);
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
    private function caseArray(CaseResult $case): array
    {
        return [
            'id' => $case->id,
            'version' => $case->version,
            'purpose' => $case->purpose->value,
            'status' => $case->status->value,
            'trusted_setup_fingerprint' => $case->trustedSetupFingerprint,
            'untrusted_input_fingerprint' => $case->untrustedInputFingerprint,
            'error_class' => $case->errorClass,
            'assertions' => array_map(
                static fn (AssertionResult $assertion): array => [
                    'assertion' => $assertion->assertion,
                    'passed' => $assertion->passed,
                    'message' => $assertion->message,
                ],
                $case->assertions,
            ),
            'observation' => $case->observation === null
                ? null
                : $this->observationArray($case->observation),
        ];
    }

    /** @return array<string, mixed> */
    private function observationArray(ObservationEvidence $observation): array
    {
        return [
            'disposition' => $observation->disposition?->value,
            'executed' => $observation->executed,
            'output_fingerprint' => $observation->outputFingerprint,
            'side_effect_fingerprints' => $observation->sideEffectFingerprints,
            'tool_calls' => array_map(
                static fn (ToolObservation $toolCall): array => [
                    'capability' => $toolCall->capability,
                    'argument_fingerprint' => $toolCall->argumentFingerprint,
                    'disposition' => $toolCall->disposition?->value,
                    'executed' => $toolCall->executed,
                ],
                $observation->toolCalls,
            ),
        ];
    }
}
