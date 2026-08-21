<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision as VerdictDecision;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Exceptions\CapabilityDefinitionFailed;
use Fissible\Verdict\Tests\Fixtures\Capabilities\AffirmedCapability;
use Fissible\Verdict\VerdictManager;

beforeEach(function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): VerdictDecision
        {
            return VerdictDecision::permit();
        }
    });
});

it('registers discovered capabilities when the application finishes booting', function (): void {
    bootDiscovery('Capabilities');

    expect(app(CapabilityRegistry::class)->has('fixtures.affirmed'))->toBeTrue();
});

/**
 * The property that must never erode: nothing downstream may learn to tell a discovered capability
 * from a hand-registered one.
 */
it('makes a discovered capability indistinguishable from a manually registered one', function (): void {
    bootDiscovery('Capabilities');
    $verdict = app(VerdictManager::class);

    $discovered = $verdict->registeredCapability('fixtures.affirmed');
    $evaluation = $verdict->evaluate(ActionEnvelope::wrap(
        new ActionProposal('fixtures.affirmed', [], 'call-discovered'),
        new ActionContext(actor: 72),
    ));

    expect($discovered)->toBeInstanceOf(Capability::class)
        ->and($discovered->name)->toBe('fixtures.affirmed')
        ->and($evaluation->decision->disposition)->toBe(Disposition::Permit)
        ->and($evaluation->capability?->configurationFingerprint())
        ->toBe(AffirmedCapability::make()->configurationFingerprint());
});

/**
 * Discovery ships on by default, which is only safe because the contract gates it. Every application
 * upgrading to this release has an app/Capabilities/ full of classes that implement nothing — so the
 * upgrade must register nothing and fail nothing. This is the test that proves that judgment rather
 * than asserting it.
 */
it('registers nothing and fails nothing for a directory of capabilities that predate the contract', function (): void {
    bootDiscovery('LegacyCapabilities');

    expect(app(CapabilityRegistry::class)->has('fixtures.legacy'))->toBeFalse()
        ->and(app(CapabilityRegistry::class)->all())->toBe([]);
});

it('discovers nothing when no paths are configured', function (): void {
    bootDiscovery();

    expect(app(CapabilityRegistry::class)->all())->toBe([]);
});

it('fails the boot when a discovered definition cannot be built', function (): void {
    expect(fn (): mixed => bootDiscovery('ThrowingCapabilities'))
        ->toThrow(CapabilityDefinitionFailed::class, 'Finish the TODOs');
});

/**
 * booted(), not boot(): application providers register their capabilities first, so a capability
 * registered both ways collides deterministically instead of depending on provider order.
 */
it('names the manual registration when a capability is registered both ways', function (): void {
    app(VerdictManager::class)->capability(AffirmedCapability::make());

    expect(fn (): mixed => bootDiscovery('Capabilities'))
        ->toThrow(CapabilityDefinitionFailed::class, 'already registered manually');
});

it('defaults the discovery path to where the generator writes', function (): void {
    expect(config('verdict.capabilities.discovery.paths'))->toBe([app_path('Capabilities')]);
});
