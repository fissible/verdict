<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\ObservationAssertion;
use Fissible\Verdict\Support\SystemClock;
use InvalidArgumentException;
use Throwable;

final readonly class SecuritySuite
{
    /**
     * @param  list<EvaluationCase>  $cases
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $cases,
        public ReproductionMetadata $reproduction = new ReproductionMetadata,
    ) {
        if (trim($this->name) === '' || trim($this->version) === '') {
            throw new InvalidArgumentException('A security suite must have a non-empty name and version.');
        }

        if ($this->cases === []) {
            throw new InvalidArgumentException('A security suite must contain at least one case.');
        }

        $caseIds = [];

        $this->assertCases($this->cases);

        foreach ($this->cases as $case) {
            if (isset($caseIds[$case->id])) {
                throw new InvalidArgumentException("Evaluation case IDs must be unique; duplicate [{$case->id}].");
            }

            $caseIds[$case->id] = true;
        }
    }

    /**
     * @param  array<array-key, mixed>  $cases
     */
    private function assertCases(array $cases): void
    {
        foreach ($cases as $case) {
            if (! $case instanceof EvaluationCase) {
                throw new InvalidArgumentException('Every suite case must be an EvaluationCase.');
            }
        }
    }

    public function run(?Clock $clock = null): SuiteResult
    {
        $clock ??= new SystemClock;
        $startedAt = $clock->now();
        $results = [];

        foreach ($this->cases as $case) {
            $results[] = $this->runCase($case);
        }

        return new SuiteResult(
            suite: $this->name,
            version: $this->version,
            reproduction: $this->reproduction,
            startedAt: $startedAt,
            completedAt: $clock->now(),
            cases: $results,
        );
    }

    private function runCase(EvaluationCase $case): CaseResult
    {
        if ($case->blockedBy !== null) {
            return $this->result(
                $case,
                status: CaseStatus::Pending,
                assertions: [],
                observation: null,
                blockedBy: $case->blockedBy,
            );
        }

        // Two try blocks, not one. A throw from `execute()` means there is no observation to
        // record, so the result carries none. A throw from an ASSERTION — `ExecutionAwaitsApproval`
        // and `CapabilityNotAttempted` are both routine — is a different thing: the run produced a
        // real observation and only the verdict on it is missing. Folding both into one catch
        // discarded the evidence for exactly the errored cases whose evidence matters most, which
        // is what made `LiveEvaluationRunner`'s control-arm challenge check unreachable.
        try {
            $observation = $case->execute();
        } catch (Throwable $error) {
            return $this->result(
                $case,
                status: CaseStatus::Error,
                assertions: [],
                observation: null,
                errorClass: $error::class,
            );
        }

        $evidence = ObservationEvidence::fromObservation($observation);

        try {
            $assertions = array_map(
                static fn (ObservationAssertion $assertion): AssertionResult => $assertion->evaluate($observation),
                $case->assertions,
            );
        } catch (Throwable $error) {
            return $this->result(
                $case,
                status: CaseStatus::Error,
                assertions: [],
                observation: $evidence,
                errorClass: $error::class,
            );
        }

        $passed = true;

        foreach ($assertions as $assertion) {
            if (! $assertion->passed) {
                $passed = false;
                break;
            }
        }

        return $this->result(
            $case,
            status: $passed ? CaseStatus::Passed : CaseStatus::Failed,
            assertions: $assertions,
            observation: $evidence,
        );
    }

    /** @param list<AssertionResult> $assertions */
    private function result(
        EvaluationCase $case,
        CaseStatus $status,
        array $assertions,
        ?ObservationEvidence $observation,
        ?string $errorClass = null,
        ?string $blockedBy = null,
    ): CaseResult {
        return new CaseResult(
            id: $case->id,
            version: $case->version,
            purpose: $case->purpose,
            status: $status,
            trustedSetupFingerprint: $case->input->trustedSetupFingerprint(),
            untrustedInputFingerprint: $case->input->untrustedInputFingerprint(),
            assertions: $assertions,
            observation: $observation,
            errorClass: $errorClass,
            blockedBy: $blockedBy,
        );
    }
}
