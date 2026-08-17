<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityDiscovery;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Facades\Verdict;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitConsumption;
use Fissible\Verdict\RateLimits\RateLimitOutcome;
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

it('fails CI when a configured custom store cannot be resolved', function (): void {
    config()->set('verdict.rate_limits.store', UnresolvableRateLimitStore::class);

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.custom-limited', 'view', fn (ActionEnvelope $envelope): int => 1)
            ->rateLimit(RateLimitPolicy::fixedWindow('orders-per-minute', 1, 60, fn (ActionEnvelope $envelope, int $target): array => ['actor' => 1])),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Configured rate-limit store could not be resolved.')
        ->assertExitCode(1);
});

interface UnresolvableRateLimitStoreDependency {}

final class UnresolvableRateLimitStore implements RateLimitStore
{
    public function __construct(UnresolvableRateLimitStoreDependency $dependency) {}

    public function consume(RateLimitConsumption $consumption): RateLimitOutcome
    {
        throw new RuntimeException('This store should never be consumed by the audit.');
    }
}

it('warns without failing when the shipped no-op evidence recorder is configured', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('no-op evidence recorder')
        ->assertExitCode(0);
});

it('fails under --strict when only advisory warnings are present', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    $this->artisan('verdict:validate', ['--strict' => true])
        ->expectsOutputToContain('no-op evidence recorder')
        ->assertExitCode(1);
});

it('warns when a confirmation-gated capability has no approver release policy registered', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('no context release policy is registered for the approver route')
        ->assertExitCode(0);
});

it('does not warn about the approver route once a policy is registered for it', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );
    Verdict::releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Untrusted),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('approver route')
        ->assertExitCode(0);
});

it('does not warn about the approver route when no capability requires confirmation', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.view', 'view', fn (ActionEnvelope $envelope): int => 1),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('approver route')
        ->assertExitCode(0);
});

it('does not warn about the recorder when a real one is configured', function (): void {
    config()->set('verdict.evidence.recorder', InMemoryEvidenceRecorder::class);

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('no-op evidence recorder')
        ->assertExitCode(0);
});

function bindUnaffirmedDiscovery(string $directory): void
{
    app()->instance(CapabilityDiscovery::class, new CapabilityDiscovery(
        rootPath: dirname(__DIR__).'/Fixtures',
        rootNamespace: 'Fissible\\Verdict\\Tests\\Fixtures\\',
        paths: [dirname(__DIR__).'/Fixtures/'.$directory],
    ));
}

/**
 * Inert is safe; silent is not legible. A generated capability sitting in the discovery path,
 * finished or unfinished but never affirmed, is the one state discovery leaves invisible — so it
 * prints on every run, the way the no-op recorder warning does.
 */
it('names a capability class that never affirmed the contract, without being asked twice', function (): void {
    bindUnaffirmedDiscovery('LegacyCapabilities');

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('LegacyOrderCapability')
        ->expectsOutputToContain('never affirmed')
        ->assertExitCode(0);
});

it('lets --strict fail on an unaffirmed class without changing what is printed', function (): void {
    bindUnaffirmedDiscovery('LegacyCapabilities');

    $this->artisan('verdict:validate', ['--strict' => true])
        ->expectsOutputToContain('LegacyOrderCapability')
        ->assertExitCode(1);
});

it('names an abstract class in a discovery path as never registerable', function (): void {
    bindUnaffirmedDiscovery('Capabilities');

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('AbstractAffirmedCapability')
        ->expectsOutputToContain('abstract')
        ->assertExitCode(0);
});
