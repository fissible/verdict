<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Throwable;

final readonly class ObservationEvidence
{
    /**
     * @param  list<ToolObservation>  $toolCalls
     * @param  list<string>  $sideEffectFingerprints
     * @param  int  $challengeCount  How many approval challenges the observation carried — a count,
     *                               never their content. Deliberately not projected into
     *                               `EvaluationReport::observationArray()`: challenge facts stay
     *                               assertion-only (ADR 0029 decision 2), so the report schema is
     *                               unchanged and a round-tripped result reconstructs this as 0.
     *                               It exists so `LiveEvaluationRunner` can tell an accidentally
     *                               guarded control arm from an unguarded one without a raw
     *                               `Observation` riding along on `CaseResult`.
     */
    public function __construct(
        public ?Disposition $disposition,
        public bool $executed,
        public array $toolCalls,
        public array $sideEffectFingerprints,
        public ?string $outputFingerprint,
        public int $challengeCount = 0,
    ) {}

    public static function fromObservation(Observation $observation): self
    {
        return new self(
            disposition: $observation->disposition,
            executed: $observation->executed,
            toolCalls: $observation->toolCalls,
            sideEffectFingerprints: array_map(
                static fn (string $sideEffect): string => hash('sha256', $sideEffect),
                $observation->sideEffects,
            ),
            outputFingerprint: self::outputFingerprint($observation->output),
            challengeCount: count($observation->challenges),
        );
    }

    private static function outputFingerprint(mixed $output): ?string
    {
        if ($output === null) {
            return null;
        }

        try {
            return ArgumentFingerprint::make(['output' => $output]);
        } catch (Throwable) {
            return null;
        }
    }
}
