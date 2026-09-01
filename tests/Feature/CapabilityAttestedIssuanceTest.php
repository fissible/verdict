<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ResourceProjection;

// ADR 0038 §5 — a capability opts into the STRICT (issuance-blocking, attested) tier with a per-capability flag:
//   $capability->requiresAttestedIssuance()  →  attestedIssuanceRequirement(): bool
// The flag is immutable (carried through every with-style builder), defaults to false, and is SECURITY MATERIAL:
// a declared strict posture enters the content-addressed configuration fingerprint (ADR 0017), but SPARSELY —
// present only when true, so a package upgrade alone changes no existing capability's identity.

function attestedFlagBaseCapability(): Capability
{
    return Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->requiresConfirmation(bindUsing: fn (ActionEnvelope $e, array $t): array => ['b' => 1], reason: 'Confirm.')
        ->executionTarget(acceptTestSnapshot('attest-flag-target'));
}

// ── the flag ────────────────────────────────────────────────────────────────────────────────────────

it('defaults attested-issuance to not required', function (): void {
    expect(attestedFlagBaseCapability()->attestedIssuanceRequirement())->toBeFalse();
});

it('opts into attested issuance through the builder', function (): void {
    expect(attestedFlagBaseCapability()->requiresAttestedIssuance()->attestedIssuanceRequirement())->toBeTrue()
        ->and(attestedFlagBaseCapability()->requiresAttestedIssuance(false)->attestedIssuanceRequirement())->toBeFalse();
});

// ── the flag survives every with-style builder method (Capability is immutable) ───────────────────────

it('carries the attested-issuance flag through every builder method, whichever order it is registered', function (): void {
    // Registered FIRST, then every other builder method chained after it.
    $first = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->requiresAttestedIssuance()
        ->executeUsing(fn (): string => 'ok')
        ->requiresConfirmation(bindUsing: fn (ActionEnvelope $e, array $t): array => ['b' => 1], reason: 'r')
        ->rateLimit(RateLimitPolicy::fixedWindow(name: 'l', limit: 1, windowSeconds: 60, keyUsing: fn (ActionEnvelope $e, mixed $t): array => ['k' => 1]))
        ->atMostOnce(ExecutionClaimPolicy::named('claim', fn (ActionEnvelope $e, mixed $t): array => ['k' => 1]))
        ->executionTarget(acceptTestSnapshot('attest-flag-target'))
        ->configurationVersion('v1')
        ->requiresIntentRecord(true)
        ->describeForApprover(fn (ActionEnvelope $e, mixed $t, array $b): string => 'described')
        ->resourceProjection(ResourceProjection::declared('rec/v1', fn (ActionEnvelope $e, mixed $t): array => ['r' => 1]));

    // Registered LAST, after every other builder method.
    $last = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executeUsing(fn (): string => 'ok')
        ->requiresConfirmation(bindUsing: fn (ActionEnvelope $e, array $t): array => ['b' => 1], reason: 'r')
        ->rateLimit(RateLimitPolicy::fixedWindow(name: 'l', limit: 1, windowSeconds: 60, keyUsing: fn (ActionEnvelope $e, mixed $t): array => ['k' => 1]))
        ->atMostOnce(ExecutionClaimPolicy::named('claim', fn (ActionEnvelope $e, mixed $t): array => ['k' => 1]))
        ->executionTarget(acceptTestSnapshot('attest-flag-target'))
        ->configurationVersion('v1')
        ->requiresIntentRecord(true)
        ->describeForApprover(fn (ActionEnvelope $e, mixed $t, array $b): string => 'described')
        ->resourceProjection(ResourceProjection::declared('rec/v1', fn (ActionEnvelope $e, mixed $t): array => ['r' => 1]))
        ->requiresAttestedIssuance();

    expect($first->attestedIssuanceRequirement())->toBeTrue()
        ->and($last->attestedIssuanceRequirement())->toBeTrue()
        // …and requiresAttestedIssuance(), registered LAST, preserved every prior configuration it cloned over.
        ->and($last->confirmationRequired())->toBeTrue()
        ->and($last->rateLimitPolicy())->not->toBeNull()
        ->and($last->executionClaimPolicy())->not->toBeNull()
        ->and($last->executionTargetPolicy())->not->toBeNull()
        ->and($last->intentRecordRequirement())->toBeTrue()
        ->and($last->declaredResourceProjection())->not->toBeNull();
});

// ── a declared strict posture is security material: it enters the fingerprint, sparsely ────────────────

it('adds the strict posture to the declared configuration only when required', function (): void {
    expect(attestedFlagBaseCapability()->requiresAttestedIssuance()->declaredConfiguration())
        ->toHaveKey('requires_attested_issuance', true)
        // Default / explicitly-false capabilities do NOT carry the key — a package upgrade changes no identity.
        ->and(attestedFlagBaseCapability()->declaredConfiguration())->not->toHaveKey('requires_attested_issuance')
        ->and(attestedFlagBaseCapability()->requiresAttestedIssuance(false)->declaredConfiguration())->not->toHaveKey('requires_attested_issuance');
});

it('changes the configuration fingerprint when strict issuance is required, but not when it is false', function (): void {
    $base = attestedFlagBaseCapability();

    expect($base->requiresAttestedIssuance()->configurationFingerprint())->not->toBe($base->configurationFingerprint())
        // false is the default absence, so it must be byte-identical to never declaring it.
        ->and($base->requiresAttestedIssuance(false)->configurationFingerprint())->toBe($base->configurationFingerprint());
});
