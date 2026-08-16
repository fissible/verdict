<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence\Events;

/**
 * A capability requiring confirmation or at-most-once execution ran under a no-op evidence recorder,
 * so its decisions are recorded nowhere. Dispatched at most once per process (#194).
 *
 * Deliberately names the *class* of affected capabilities, not a single one: if this fires while
 * `orders.refund` runs and `orders.cancel` is also running unrecorded, naming one capability would
 * let an operator wrongly conclude only that one is affected. Evidence is not an authorization gate
 * ([ADR 0007](../../../docs/adr/0007-evidence-layering.md)), so this is advisory — it never blocks
 * execution and never throws.
 */
final readonly class ConsequentialActionUnrecorded
{
    public const string MESSAGE = 'Capabilities requiring confirmation or at-most-once execution are running under a no-op evidence recorder (verdict.evidence.recorder = NullEvidenceRecorder); their decisions are recorded nowhere. Configure a durable recorder to retain an audit trail.';

    public function __construct(public string $message = self::MESSAGE) {}
}
