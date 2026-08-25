<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\RateLimits\RateLimitPolicy;

function intentRecordCapability(): Capability
{
    return Capability::usingPolicy('orders.refund', 'refund', fn () => null);
}

it('leaves the intent-record requirement undeclared by default', function (): void {
    expect(intentRecordCapability()->intentRecordRequirement())->toBeNull();
});

it('declares the intent-record requirement in both directions', function (): void {
    expect(intentRecordCapability()->requiresIntentRecord()->intentRecordRequirement())->toBeTrue()
        ->and(intentRecordCapability()->requiresIntentRecord(false)->intentRecordRequirement())->toBeFalse();
});

it('keeps the requirement through later fluent composition', function (): void {
    $capability = intentRecordCapability()
        ->requiresIntentRecord()
        ->rateLimit(RateLimitPolicy::fixedWindow('refund-limit', 5, 60, fn () => ['key' => 'k']));

    expect($capability->intentRecordRequirement())->toBeTrue();
});

it('omits the sparse key from declared configuration until the requirement is declared', function (): void {
    // Sparse deliberately: an unconditional key would change every capability's configuration
    // fingerprint on package upgrade with no application change — spurious "configuration
    // changed" signals in evidence. A declared posture is security-material and rightly enters
    // the content-addressed identity (ADR 0017); an undeclared one keeps the identity untouched.
    expect(intentRecordCapability()->declaredConfiguration())
        ->not->toHaveKey('requires_intent_record')
        ->and(intentRecordCapability()->requiresIntentRecord()->declaredConfiguration())
        ->toHaveKey('requires_intent_record', true)
        ->and(intentRecordCapability()->requiresIntentRecord(false)->declaredConfiguration())
        ->toHaveKey('requires_intent_record', false);
});

it('changes the configuration fingerprint only when the requirement is declared', function (): void {
    $undeclared = intentRecordCapability();

    expect(intentRecordCapability()->configurationFingerprint())->toBe($undeclared->configurationFingerprint())
        ->and($undeclared->requiresIntentRecord()->configurationFingerprint())
        ->not->toBe($undeclared->configurationFingerprint())
        ->and($undeclared->requiresIntentRecord(false)->configurationFingerprint())
        ->not->toBe($undeclared->configurationFingerprint())
        ->and($undeclared->requiresIntentRecord(false)->configurationFingerprint())
        ->not->toBe($undeclared->requiresIntentRecord()->configurationFingerprint());
});
