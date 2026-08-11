<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\InMemoryCapabilityConfigurationStore;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Support\Facades\Gate as GateFacade;

final class FingerprintCustomer implements AuthenticatableContract
{
    use Authenticatable;

    public function __construct(public int $id) {}
}

final readonly class FingerprintOrder
{
    public function __construct(public int $id, public int $customerId) {}
}

final class FingerprintOrderPolicy
{
    public function view(FingerprintCustomer $customer, FingerprintOrder $order): Response
    {
        return $customer->id === $order->customerId
            ? Response::allow()
            : Response::deny('Not the owning customer.');
    }
}

function inMemoryEvidenceRecorderForFingerprintTest(): InMemoryEvidenceRecorder
{
    $recorder = app(EvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        throw new LogicException('Expected the in-memory evidence recorder.');
    }

    return $recorder;
}

beforeEach(function (): void {
    GateFacade::policy(FingerprintOrder::class, FingerprintOrderPolicy::class);
});

it('records the registered capability configuration fingerprint on every evaluation', function (): void {
    $order = new FingerprintOrder(1001, 72);
    $capability = Capability::usingPolicy(
        name: 'orders.fingerprint-view',
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): FingerprintOrder => $order,
    )->rateLimit(RateLimitPolicy::fixedWindow('per-customer', 5, 86400, fn (): array => ['customer' => 72]));

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    $envelope = ActionEnvelope::wrap(
        proposal: new ActionProposal(capability: 'orders.fingerprint-view', arguments: []),
        context: new ActionContext(new FingerprintCustomer(72)),
    );

    $verdict->run($envelope, fn (): string => 'ok');

    $recorded = inMemoryEvidenceRecorderForFingerprintTest()->all();

    expect($recorded)->not->toBeEmpty();

    foreach ($recorded as $evidence) {
        expect($evidence->configurationFingerprint)->toBe($capability->configurationFingerprint());
    }

    $configurations = app(CapabilityConfigurationStore::class);

    expect($configurations)->toBeInstanceOf(InMemoryCapabilityConfigurationStore::class)
        ->and($configurations->all())->toHaveKey($recorded[0]->configurationFingerprint)
        ->and($configurations->all()[$recorded[0]->configurationFingerprint]['configuration'])
        ->toBe($capability->declaredConfiguration());
});

it('records no configuration fingerprint when the capability is unregistered', function (): void {
    $verdict = app(VerdictManager::class);

    $envelope = ActionEnvelope::wrap(
        proposal: new ActionProposal(capability: 'orders.unregistered-fingerprint', arguments: []),
        context: new ActionContext(new FingerprintCustomer(72)),
    );

    $verdict->run($envelope, fn (): string => 'ok');

    $evidence = inMemoryEvidenceRecorderForFingerprintTest()->latest();

    expect($evidence)->not->toBeNull()
        ->and($evidence?->configurationFingerprint)->toBeNull();
});
