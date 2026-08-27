<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\ExecutionResult;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use InvalidArgumentException;

final readonly class Observation
{
    /**
     * Provenance on the live Observation is for attack-pack assertions only.
     * ObservationEvidence::fromObservation() does not project these entries into
     * reports or baselines; #43 must not redesign report evidence. Future
     * provenance/decision work (#29/#30) may extend reporting separately.
     *
     * Challenge observations are assertion-only like provenance entries, per ADR 0029.
     *
     * Predicate observations (the statements the connection listener captured during execution)
     * are likewise assertion-only — they exist for the filtered-permit comparison (#251), not for
     * reporting.
     *
     * Recorded actor and subject fingerprints are likewise assertion-only. They retain the
     * invocation's decision-evidence identity for assertions, and must not be projected into
     * ObservationEvidence, reports, or baselines.
     *
     * @param  list<ToolObservation>  $toolCalls
     * @param  list<string>  $sideEffects
     * @param  list<ProvenanceEntry>  $provenanceEntries
     * @param  list<ChallengeObservation>  $challenges
     * @param  list<PredicateObservation>  $predicates
     */
    public function __construct(
        public ?Disposition $disposition,
        public bool $executed,
        public mixed $output = null,
        public array $toolCalls = [],
        public array $sideEffects = [],
        public array $provenanceEntries = [],
        public array $challenges = [],
        public array $predicates = [],
        public ?string $recordedActorFingerprint = null,
        public ?string $recordedSubjectFingerprint = null,
    ) {
        $this->assertToolCalls($this->toolCalls);
        $this->assertSideEffects($this->sideEffects);
        $this->assertProvenanceEntries($this->provenanceEntries);
        $this->assertChallenges($this->challenges);
        $this->assertPredicates($this->predicates);
    }

    /**
     * @param  list<string>  $sideEffects
     */
    public static function fromExecutionResult(ExecutionResult $result, array $sideEffects = []): self
    {
        $proposal = $result->evaluation->envelope->proposal;

        return new self(
            disposition: $result->evaluation->decision->disposition,
            executed: $result->executed,
            output: $result->output,
            toolCalls: [new ToolObservation(
                capability: $proposal->capability,
                argumentFingerprint: ArgumentFingerprint::make($proposal->arguments),
                disposition: $result->evaluation->decision->disposition,
                executed: $result->executed,
            )],
            sideEffects: $sideEffects,
        );
    }

    /**
     * @param  array<array-key, mixed>  $toolCalls
     */
    private function assertToolCalls(array $toolCalls): void
    {
        foreach ($toolCalls as $toolCall) {
            if (! $toolCall instanceof ToolObservation) {
                throw new InvalidArgumentException('Every observed tool call must be a ToolObservation.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $sideEffects
     */
    private function assertSideEffects(array $sideEffects): void
    {
        foreach ($sideEffects as $sideEffect) {
            if (! is_string($sideEffect) || trim($sideEffect) === '') {
                throw new InvalidArgumentException('Every observed side effect must have a non-empty name.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $provenanceEntries
     */
    private function assertProvenanceEntries(array $provenanceEntries): void
    {
        foreach ($provenanceEntries as $entry) {
            if (! $entry instanceof ProvenanceEntry) {
                throw new InvalidArgumentException('Every observed provenance entry must be a ProvenanceEntry.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $challenges
     */
    private function assertChallenges(array $challenges): void
    {
        foreach ($challenges as $challenge) {
            if (! $challenge instanceof ChallengeObservation) {
                throw new InvalidArgumentException('Every observed challenge must be a ChallengeObservation.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $predicates
     */
    private function assertPredicates(array $predicates): void
    {
        foreach ($predicates as $predicate) {
            if (! $predicate instanceof PredicateObservation) {
                throw new InvalidArgumentException('Every observed predicate must be a PredicateObservation.');
            }
        }
    }
}
