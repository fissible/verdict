<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use Fissible\Verdict\Decisions\Evaluation;

final readonly class DecisionEvidence
{
    public function __construct(
        public string $envelopeId,
        public string $capability,
        public string $stage,
        public string $disposition,
        public ?string $reason,
        public string $argumentFingerprint,
        public ?string $idempotencyKey,
        public DateTimeImmutable $recordedAt,
    ) {}

    public static function fromEvaluation(Evaluation $evaluation): self
    {
        return new self(
            envelopeId: $evaluation->envelope->id,
            capability: $evaluation->envelope->proposal->capability,
            stage: $evaluation->stage->value,
            disposition: $evaluation->decision->disposition->value,
            reason: $evaluation->decision->reason,
            argumentFingerprint: ArgumentFingerprint::make($evaluation->envelope->proposal->arguments),
            idempotencyKey: $evaluation->envelope->proposal->idempotencyKey,
            recordedAt: new DateTimeImmutable,
        );
    }
}
