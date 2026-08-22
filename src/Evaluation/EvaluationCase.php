<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Closure;
use Fissible\Verdict\Contracts\ObservationAssertion;
use InvalidArgumentException;
use LogicException;

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
        public ?string $blockedBy = null,
        public SafeOutcome $safeOutcome = SafeOutcome::Blocked,
    ) {
        if ($this->safeOutcome === SafeOutcome::FilteredPermit && $this->purpose !== CasePurpose::Security) {
            throw new InvalidArgumentException(
                'A filtered-permit safe outcome describes an attack surviving a guard; only a security case may declare it.',
            );
        }

        if (trim($this->id) === '' || trim($this->version) === '') {
            throw new InvalidArgumentException('An evaluation case must have a non-empty ID and version.');
        }

        if ($this->blockedBy !== null) {
            if (trim($this->blockedBy) === '') {
                throw new InvalidArgumentException('A pending evaluation case must name what blocks it.');
            }

            if ($this->assertions !== []) {
                throw new InvalidArgumentException('A pending evaluation case cannot define assertions.');
            }
        } elseif ($this->assertions === []) {
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
     * An attack case whose safe outcome is a filtered permit (#251): the tool executes under
     * guard, and the assertions move to result content (owned rows present, foreign rows absent,
     * by fixture identity) and to the executed predicate's digest. `SecuritySuite` runs it like
     * any attack case; the control arm's 2×2 reads its passing control trials as self-declined
     * rather than inconsistent — see {@see SafeOutcome::FilteredPermit}.
     *
     * @param  Closure(CaseInput): Observation  $runner
     * @param  list<ObservationAssertion>  $assertions
     */
    public static function filteredPermitAttack(
        string $id,
        string $version,
        CaseInput $input,
        Closure $runner,
        array $assertions,
    ): self {
        return new self(
            $id,
            $version,
            CasePurpose::Security,
            $input,
            $runner,
            $assertions,
            safeOutcome: SafeOutcome::FilteredPermit,
        );
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

    public static function pending(
        string $id,
        string $version,
        CasePurpose $purpose,
        CaseInput $input,
        string $blockedBy,
    ): self {
        return new self(
            $id,
            $version,
            $purpose,
            $input,
            static fn (CaseInput $input): never => throw new LogicException('A pending evaluation case must not execute.'),
            [],
            $blockedBy,
        );
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
