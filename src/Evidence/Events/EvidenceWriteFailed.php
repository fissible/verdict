<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence\Events;

/**
 * An evidence write failed on a path where propagating it would misreport execution.
 *
 * ADR 0007's Update (#149) scopes the propagation rule: an evidence-write failure occurring after
 * a successful executor must not reach the caller, because the only channel available there is an
 * exception, and an exception from an evidence write is indistinguishable from one raised by the
 * executor. The failure surfaces here instead, so it is observable without being able to rewrite
 * the caller's understanding of what executed.
 */
final readonly class EvidenceWriteFailed
{
    public function __construct(
        public string $capability,
        public string $stage,
        public ?string $invocationId,
        public string $message,
    ) {}
}
