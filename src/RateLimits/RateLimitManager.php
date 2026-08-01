<?php

declare(strict_types=1);

namespace Fissible\Verdict\RateLimits;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\ArgumentFingerprint;

final readonly class RateLimitManager
{
    public function __construct(
        private RateLimitStore $store,
        private Clock $clock,
    ) {}

    public function consume(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
    {
        $policy = $capability->rateLimitPolicy();

        if ($policy === null) {
            return Decision::permit('Capability has no semantic execution rate limit.');
        }

        $fingerprint = ArgumentFingerprint::make([
            'capability' => $capability->name,
            'policy' => $policy->name,
            'algorithm' => 'fixed_window',
            'window_seconds' => $policy->windowSeconds,
            'binding' => $policy->binding($envelope, $target),
        ]);
        $outcome = $this->store->consume(new RateLimitConsumption(
            bucketFingerprint: $fingerprint,
            limit: $policy->limit,
            windowSeconds: $policy->windowSeconds,
            at: $this->clock->now(),
        ));
        $metadata = [
            'rate_limit_key_fingerprint' => $fingerprint,
            'rate_limit_policy' => $policy->name,
            'rate_limit_limit' => $outcome->limit,
            'rate_limit_remaining' => $outcome->remaining,
            'rate_limit_reset_at' => $outcome->resetAt->format(DATE_ATOM),
        ];

        return $outcome->allowed
            ? Decision::permit('Semantic execution rate limit permits this attempt.', $metadata)
            : Decision::throttle($policy->reason ?? 'Semantic execution rate limit exceeded.', $metadata);
    }
}
