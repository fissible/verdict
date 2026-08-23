<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities\Events;

/**
 * A capability-configuration audit write was skipped because of a database failure.
 *
 * The configuration registry is an audit record, not a decision input — nothing in the decision
 * path reads it — so a failed write must not take boot down (#240, #256). It also must not
 * disappear: a fresh clone booting before its database exists is transient, but the same skip
 * fires for a permanently misconfigured connection, and only the operator can tell those apart.
 * This event is the per-process signal; `verdict:validate` is the deploy-time one.
 */
final readonly class CapabilityConfigurationUnrecorded
{
    public function __construct(
        public string $capability,
        public string $configurationFingerprint,
        public string $reason,
    ) {}
}
