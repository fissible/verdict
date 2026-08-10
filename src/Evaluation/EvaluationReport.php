<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use DateTimeImmutable;
use Fissible\Verdict\Decisions\Disposition;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use ValueError;

final readonly class EvaluationReport implements JsonSerializable
{
    public const string SCHEMA = 'verdict.evaluation-report.v1';

    public function __construct(private SuiteResult $result) {}

    /** @throws JsonException */
    public static function fromJson(string $json): self
    {
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($report) || array_is_list($report)) {
            throw new InvalidArgumentException('An evaluation report must be a JSON object.');
        }

        return self::fromArray($report);
    }

    /** @param array<string, mixed> $report */
    public static function fromArray(array $report): self
    {
        if (($report['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('The evaluation report uses an unsupported schema.');
        }

        $suite = self::requiredString($report, 'suite', 'report');
        $version = self::requiredString($report, 'version', 'report');
        $startedAt = self::date($report, 'started_at');
        $completedAt = self::date($report, 'completed_at');

        if ($completedAt < $startedAt) {
            throw new InvalidArgumentException('The evaluation report completed before it started.');
        }

        $reproduction = $report['reproduction'] ?? null;
        $cases = $report['cases'] ?? null;

        if (! is_array($reproduction) || ($reproduction !== [] && array_is_list($reproduction))) {
            throw new InvalidArgumentException('The evaluation report reproduction metadata must be an object.');
        }

        if (! is_array($cases) || ! array_is_list($cases)) {
            throw new InvalidArgumentException('The evaluation report cases must be a list.');
        }

        $components = [];

        foreach ($reproduction as $name => $componentVersion) {
            if (! is_string($name) || ! is_string($componentVersion)) {
                throw new InvalidArgumentException('Reproduction component names and versions must be strings.');
            }

            $components[$name] = $componentVersion;
        }

        $caseResults = [];
        $caseIds = [];

        foreach ($cases as $case) {
            if (! is_array($case) || array_is_list($case)) {
                throw new InvalidArgumentException('Every evaluation report case must be an object.');
            }

            $caseResult = self::caseResult($case);

            if (isset($caseIds[$caseResult->id])) {
                throw new InvalidArgumentException("The evaluation report contains duplicate case [{$caseResult->id}].");
            }

            $caseIds[$caseResult->id] = true;
            $caseResults[] = $caseResult;
        }

        $result = new SuiteResult(
            suite: $suite,
            version: $version,
            reproduction: new ReproductionMetadata($components),
            startedAt: $startedAt,
            completedAt: $completedAt,
            cases: $caseResults,
        );

        self::assertSummary($report, $result);

        return new self($result);
    }

    public function result(): SuiteResult
    {
        return $this->result;
    }

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
            'pending' => $score->pending,
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
            'blocked_by' => $case->blockedBy,
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

    /** @param array<string, mixed> $case */
    private static function caseResult(array $case): CaseResult
    {
        $purpose = self::enumValue(
            CasePurpose::class,
            self::requiredString($case, 'purpose', 'case'),
            'case purpose',
        );
        $status = self::enumValue(
            CaseStatus::class,
            self::requiredString($case, 'status', 'case'),
            'case status',
        );
        $assertions = $case['assertions'] ?? null;

        if (! is_array($assertions) || ! array_is_list($assertions)) {
            throw new InvalidArgumentException('Every evaluation report case requires an assertions list.');
        }

        $assertionResults = [];

        foreach ($assertions as $assertion) {
            if (! is_array($assertion) || array_is_list($assertion)) {
                throw new InvalidArgumentException('Every evaluation assertion must be an object.');
            }

            $passed = $assertion['passed'] ?? null;
            $message = $assertion['message'] ?? null;

            if (! is_bool($passed) || (! is_string($message) && $message !== null)) {
                throw new InvalidArgumentException('Every evaluation assertion requires a boolean result and optional message.');
            }

            $assertionResults[] = new AssertionResult(
                assertion: self::requiredString($assertion, 'assertion', 'assertion'),
                passed: $passed,
                message: $message,
            );
        }

        $observation = $case['observation'] ?? null;
        $errorClass = $case['error_class'] ?? null;
        $blockedBy = $case['blocked_by'] ?? null;

        if (! is_string($errorClass) && $errorClass !== null) {
            throw new InvalidArgumentException('An evaluation case error class must be a string or null.');
        }

        if (! is_string($blockedBy) && $blockedBy !== null) {
            throw new InvalidArgumentException('An evaluation case blocker must be a string or null.');
        }

        return new CaseResult(
            id: self::requiredString($case, 'id', 'case'),
            version: self::requiredString($case, 'version', 'case'),
            purpose: $purpose,
            status: $status,
            trustedSetupFingerprint: self::fingerprint($case, 'trusted_setup_fingerprint'),
            untrustedInputFingerprint: self::fingerprint($case, 'untrusted_input_fingerprint'),
            assertions: $assertionResults,
            observation: self::observation($observation),
            errorClass: $errorClass,
            blockedBy: $blockedBy,
        );
    }

    private static function observation(mixed $observation): ?ObservationEvidence
    {
        if ($observation === null) {
            return null;
        }

        if (! is_array($observation) || array_is_list($observation)) {
            throw new InvalidArgumentException('An evaluation observation must be an object or null.');
        }

        $dispositionValue = $observation['disposition'] ?? null;
        $executed = $observation['executed'] ?? null;
        $toolCalls = $observation['tool_calls'] ?? null;
        $sideEffects = $observation['side_effect_fingerprints'] ?? null;
        $outputFingerprint = $observation['output_fingerprint'] ?? null;

        if (! is_bool($executed)) {
            throw new InvalidArgumentException('An evaluation observation requires an executed flag.');
        }

        if (! is_array($toolCalls) || ! array_is_list($toolCalls)) {
            throw new InvalidArgumentException('Evaluation observation tool calls must be a list.');
        }

        if (! is_array($sideEffects) || ! array_is_list($sideEffects)) {
            throw new InvalidArgumentException('Evaluation observation side effects must be a list.');
        }

        if (! is_string($outputFingerprint) && $outputFingerprint !== null) {
            throw new InvalidArgumentException('An evaluation output fingerprint must be a string or null.');
        }

        $parsedToolCalls = [];

        foreach ($toolCalls as $toolCall) {
            if (! is_array($toolCall) || array_is_list($toolCall)) {
                throw new InvalidArgumentException('Every evaluation tool call must be an object.');
            }

            $toolDisposition = $toolCall['disposition'] ?? null;
            $toolExecuted = $toolCall['executed'] ?? null;

            if (! is_bool($toolExecuted)) {
                throw new InvalidArgumentException('Every evaluation tool call requires an executed flag.');
            }

            $parsedToolCalls[] = new ToolObservation(
                capability: self::requiredString($toolCall, 'capability', 'tool call'),
                argumentFingerprint: self::fingerprint($toolCall, 'argument_fingerprint'),
                disposition: self::nullableDisposition($toolDisposition),
                executed: $toolExecuted,
            );
        }

        $parsedSideEffects = [];

        foreach ($sideEffects as $sideEffect) {
            if (! is_string($sideEffect) || preg_match('/^[a-f0-9]{64}$/', $sideEffect) !== 1) {
                throw new InvalidArgumentException('Every side-effect fingerprint must be a SHA-256 value.');
            }

            $parsedSideEffects[] = $sideEffect;
        }

        if ($outputFingerprint !== null && preg_match('/^[a-f0-9]{64}$/', $outputFingerprint) !== 1) {
            throw new InvalidArgumentException('An evaluation output fingerprint must be a SHA-256 value.');
        }

        return new ObservationEvidence(
            disposition: self::nullableDisposition($dispositionValue),
            executed: $executed,
            toolCalls: $parsedToolCalls,
            sideEffectFingerprints: $parsedSideEffects,
            outputFingerprint: $outputFingerprint,
        );
    }

    private static function nullableDisposition(mixed $value): ?Disposition
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || Disposition::tryFrom($value) === null) {
            throw new InvalidArgumentException('An evaluation disposition is invalid.');
        }

        return Disposition::from($value);
    }

    /** @param array<string, mixed> $values */
    private static function requiredString(array $values, string $key, string $context): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The evaluation {$context} requires a non-empty {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function fingerprint(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException("The evaluation report requires a SHA-256 {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $report */
    private static function date(array $report, string $key): DateTimeImmutable
    {
        $value = self::requiredString($report, $key, 'report');
        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        if ($date === false || $date->format(DATE_ATOM) !== $value) {
            throw new InvalidArgumentException("The evaluation report {$key} must use the DATE_ATOM format.");
        }

        return $date;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function enumValue(string $enum, string $value, string $label): \BackedEnum
    {
        try {
            return $enum::from($value);
        } catch (ValueError) {
            throw new InvalidArgumentException("The evaluation {$label} is invalid.");
        }
    }

    /** @param array<string, mixed> $report */
    private static function assertSummary(array $report, SuiteResult $result): void
    {
        if (($report['passed'] ?? null) !== $result->passed()) {
            throw new InvalidArgumentException('The evaluation report pass summary does not match its cases.');
        }

        $scores = $report['scores'] ?? null;

        if (! is_array($scores) || array_is_list($scores)) {
            throw new InvalidArgumentException('The evaluation report scores must be an object.');
        }

        foreach (CasePurpose::cases() as $purpose) {
            $summary = $scores[$purpose->value] ?? null;

            if (! is_array($summary) || array_is_list($summary)) {
                throw new InvalidArgumentException("The evaluation report is missing the {$purpose->value} score.");
            }

            $score = $result->score($purpose);
            $passRate = $summary['pass_rate'] ?? null;

            if (($summary['passed'] ?? null) !== $score->passed
                || ($summary['failed'] ?? null) !== $score->failed
                || ($summary['errors'] ?? null) !== $score->errors
                || ($summary['pending'] ?? 0) !== $score->pending
                || ($summary['evaluated'] ?? null) !== $score->evaluated()
                || ($summary['total'] ?? null) !== $score->total()
                || ! self::samePassRate($passRate, $score->passRate())) {
                throw new InvalidArgumentException("The evaluation report {$purpose->value} score does not match its cases.");
            }
        }
    }

    private static function samePassRate(mixed $actual, ?float $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }

        return (is_int($actual) || is_float($actual)) && (float) $actual === $expected;
    }
}
