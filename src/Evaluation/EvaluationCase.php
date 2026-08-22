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

        if ($this->safeOutcome === SafeOutcome::FilteredPermit && $this->blockedBy === null) {
            $this->assertCarriesBothOracleSides($this->assertions);
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
     * A filtered-permit case without both oracle sides silently disables the shape's semantics: a
     * blocked-shape assertion list wrapped in the declaration would relax the control arm's
     * harness-contradiction tripwire (a passing control arm reads as self-declined for this shape)
     * while measuring nothing a filtered permit is about. The two-sided oracle is the decided
     * design — owned rows present (a Utility-facet assertion) beside foreign rows absent (a
     * Security-facet one) — so the declaration requires at least one of each. Facets are read off
     * `CallbackAssertion`, which every `Assertions` factory builds; a hand-rolled
     * `ObservationAssertion` counts as Security, the default it would be stamped with anyway.
     *
     * @param  list<ObservationAssertion>  $assertions
     */
    private function assertCarriesBothOracleSides(array $assertions): void
    {
        $facets = [];

        foreach ($assertions as $assertion) {
            $facet = $assertion instanceof CallbackAssertion ? $assertion->facet : AssertionFacet::Security;
            $facets[$facet->value] = true;
        }

        if (! isset($facets[AssertionFacet::Utility->value]) || ! isset($facets[AssertionFacet::Security->value])) {
            throw new InvalidArgumentException(
                'A filtered-permit case must assert both oracle sides: owned rows present (e.g. outputIncludes) '
                .'and the security side (e.g. outputExcludes and the predicate digest assertions).',
            );
        }
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
