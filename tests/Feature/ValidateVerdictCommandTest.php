<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;

it('reports static wiring warnings without failing CI', function (): void {
    $targetResolutions = 0;

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.legacy', 'view', function (ActionEnvelope $envelope) use (&$targetResolutions): int {
            $targetResolutions++;

            return 1;
        })
            ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                name: 'legacy-snapshot',
                identityUsing: fn (ActionEnvelope $envelope, int $target): array => ['id' => $target],
            )),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Capability [orders.legacy] has no executor')
        ->expectsOutputToContain('Capability [orders.legacy] deliberately accepts a stale')
        ->assertExitCode(0);

    expect($targetResolutions)->toBe(0);
});

it('fails CI when a configured database backing table is missing', function (): void {
    config()->set('verdict.rate_limits.store', DatabaseRateLimitStore::class);
    config()->set('verdict.rate_limits.table', 'missing_verdict_rate_limit_buckets');

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.limited', 'view', fn (ActionEnvelope $envelope): int => 1)
            ->rateLimit(RateLimitPolicy::fixedWindow('orders-per-minute', 1, 60, fn (ActionEnvelope $envelope, int $target): array => ['actor' => 1])),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Configured rate-limit store requires missing table [missing_verdict_rate_limit_buckets]')
        ->assertExitCode(1);
});

it('uses the runtime database store default when the store key is omitted', function (): void {
    config()->set('verdict.rate_limits', []);

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.default-limited', 'view', fn (ActionEnvelope $envelope): int => 1)
            ->rateLimit(RateLimitPolicy::fixedWindow('orders-per-minute', 1, 60, fn (ActionEnvelope $envelope, int $target): array => ['actor' => 1])),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Configured rate-limit store requires missing table [verdict_rate_limit_buckets]')
        ->assertExitCode(1);
});
