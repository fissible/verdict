<?php

declare(strict_types=1);

namespace Fissible\Verdict\Intents;

use DateTimeImmutable;

/**
 * A write-ahead intent record: durable proof that a protected action was about to enter its
 * mutating phase, written before any security state is consumed (#160).
 *
 * Write-once and immutable by design. There is no status field and no copied gate outcomes: the
 * outcome record remains the sole authority on what actually happened, and an intent with no
 * outcome evidence referencing it IS the gap signal a compliance deployment schedules
 * verification for. A mutable intent row would be a weaker compliance artifact.
 *
 * The field set is the full standalone identity — it answers "who tried what, on what, under
 * which configuration" even if every outcome record is lost.
 */
final readonly class ActionIntent
{
    public function __construct(
        public string $id,
        public string $capability,
        public string $configurationFingerprint,
        public ?string $actorFingerprint,
        public ?string $subjectFingerprint,
        public ?string $executionTargetIdentityFingerprint,
        public string $argumentFingerprint,
        public ?string $invocationId,
        public DateTimeImmutable $recordedAt,
    ) {}
}
