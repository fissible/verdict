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
     */
    public function __construct(
        public ?Disposition $disposition,
        public bool $executed,
        public array $toolCalls,
        public array $sideEffectFingerprints,
        public ?string $outputFingerprint,
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
