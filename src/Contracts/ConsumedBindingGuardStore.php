<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use DateTimeInterface;
use Fissible\Verdict\Approvals\DerivedGuard;

interface ConsumedBindingGuardStore
{
    /**
     * The stored guard for this digest — its raw digest plus the algorithm and key version it was
     * remembered under — or null when no such guard is recorded. The scheme metadata lets a probe
     * hit re-derive and validate the match (after-match validation, ADR 0039).
     */
    public function lookup(string $digest): ?DerivedGuard;

    public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void;

    /**
     * True when any recorded guard carries a non-null algorithm — i.e. a keyed guard exists. This is
     * the keyed->keyless downgrade signal: a schemed row a keyless-effective scheme can no longer
     * re-derive, so a probe would never see it and the store must fail closed rather than reopen the
     * replay window (ADR 0039, #514 follow-up).
     */
    public function hasSchemedGuard(): bool;
}
