<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Closure;
use Fissible\Verdict\Contracts\ObservationAssertion;
use InvalidArgumentException;

final readonly class EvaluationCase
{
    /** @var Closure(CaseInput): Observation */
    private Closure $runner;

    /**
     * @param  Closure(CaseInput): Observation  $runner
     * @param  list<ObservationAssertion>  $assertions
     */
    public function __construct(
        public string $id,
        public string $version,
        public CasePurpose $purpose,
        public CaseInput $input,
        Closure $runner,
        public array $assertions,
    ) {
        if (trim($this->id) === '' || trim($this->version) === '') {
            throw new InvalidArgumentException('An evaluation case must have a non-empty ID and version.');
        }

        if ($this->assertions === []) {
            throw new InvalidArgumentException('An evaluation case must define at least one assertion.');
        }

        $this->assertAssertions($this->assertions);

        $this->runner = $runner;
    }

    public function execute(): Observation
    {
        return ($this->runner)($this->input);
    }

    /**
     * @param  Closure(CaseInput): Observation  $runner
     * @param  list<ObservationAssertion>  $assertions
     */
    public static function attack(
        string $id,
        string $version,
        CaseInput $input,
        Closure $runner,
        array $assertions,
    ): self {
        return new self($id, $version, CasePurpose::Security, $input, $runner, $assertions);
    }

    /**
     * @param  Closure(CaseInput): Observation  $runner
     * @param  list<ObservationAssertion>  $assertions
     */
    public static function utility(
        string $id,
        string $version,
        CaseInput $input,
        Closure $runner,
        array $assertions,
    ): self {
        return new self($id, $version, CasePurpose::Utility, $input, $runner, $assertions);
    }

    /**
     * @param  array<array-key, mixed>  $assertions
     */
    private function assertAssertions(array $assertions): void
    {
        foreach ($assertions as $assertion) {
            if (! $assertion instanceof ObservationAssertion) {
                throw new InvalidArgumentException('Every case assertion must implement ObservationAssertion.');
            }
        }
    }
}
