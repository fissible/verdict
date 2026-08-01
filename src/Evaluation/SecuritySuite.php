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
        try {
            $observation = $case->execute();
            $assertions = array_map(
                static fn (ObservationAssertion $assertion): AssertionResult => $assertion->evaluate($observation),
                $case->assertions,
            );
            $passed = true;

            foreach ($assertions as $assertion) {
                if (! $assertion->passed) {
                    $passed = false;
                    break;
                }
            }

            return new CaseResult(
                id: $case->id,
                version: $case->version,
                purpose: $case->purpose,
                status: $passed ? CaseStatus::Passed : CaseStatus::Failed,
                trustedSetupFingerprint: $case->input->trustedSetupFingerprint(),
                untrustedInputFingerprint: $case->input->untrustedInputFingerprint(),
                assertions: $assertions,
                observation: ObservationEvidence::fromObservation($observation),
            );
        } catch (Throwable $error) {
            return new CaseResult(
                id: $case->id,
                version: $case->version,
                purpose: $case->purpose,
                status: CaseStatus::Error,
                trustedSetupFingerprint: $case->input->trustedSetupFingerprint(),
                untrustedInputFingerprint: $case->input->untrustedInputFingerprint(),
                assertions: [],
                observation: null,
                errorClass: $error::class,
            );
        }
    }
}
