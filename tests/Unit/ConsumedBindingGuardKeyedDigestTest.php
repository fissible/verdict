<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ConsumedBindingGuard;

// Slice 9 of ADR 0039 (keyed digest opt-in, the derivation primitive). The guard digest is keyless
// sha256 by default; keyed mode is a versioned HMAC over the SAME canonical preimage. This pins the
// primitive only — no config, no issue()/consume() wiring, no persistence policy (later slices).
// The canonical preimage is sha256(toolCallId) . sha256(capability) . bindingFingerprint, so the
// component boundaries are unambiguous (each hashed component is fixed width) under either scheme.

// The preimage spelled out independently of the implementation, so a change to the derivation
// formula diverges from these expectations rather than moving with them.
function cbgPreimage(string $toolCallId, string $capability, string $bindingFingerprint): string
{
    return hash('sha256', $toolCallId).hash('sha256', $capability).$bindingFingerprint;
}

it('keeps the keyless digest as the unsalted sha256 of the canonical preimage', function (): void {
    $expected = hash('sha256', cbgPreimage('call-1', 'orders.cancel', 'fp-abc'), true);

    $digest = ConsumedBindingGuard::digest('call-1', 'orders.cancel', 'fp-abc');

    expect($digest)->toBe($expected)
        ->and(strlen($digest))->toBe(32); // raw bytes: fits the fixed BINARY(32) column
});

it('derives a keyed digest as HMAC-SHA256 of the canonical preimage under the secret', function (): void {
    $expected = hash_hmac('sha256', cbgPreimage('call-1', 'orders.cancel', 'fp-abc'), 'secret-v1', true);

    $keyed = ConsumedBindingGuard::keyed('call-1', 'orders.cancel', 'fp-abc', 'secret-v1');

    expect($keyed)->toBe($expected)
        ->and(strlen($keyed))->toBe(32); // same fixed width as the keyless digest
});

it('produces a keyed digest distinct from the keyless digest of the same triple', function (): void {
    // Keyed mode exists so a leaked snapshot is not offline-testable without the secret; the HMAC
    // output must not collapse to the bare hash.
    expect(ConsumedBindingGuard::keyed('call-1', 'orders.cancel', 'fp-abc', 'secret-v1'))
        ->not->toBe(ConsumedBindingGuard::digest('call-1', 'orders.cancel', 'fp-abc'));
});

it('produces different keyed digests under different secrets (versioned keys diverge)', function (): void {
    $v1 = ConsumedBindingGuard::keyed('call-1', 'orders.cancel', 'fp-abc', 'secret-v1');
    $v2 = ConsumedBindingGuard::keyed('call-1', 'orders.cancel', 'fp-abc', 'secret-v2');

    expect($v1)->not->toBe($v2);
});

it('is deterministic: identical inputs yield identical digests under either scheme', function (): void {
    expect(ConsumedBindingGuard::digest('call-1', 'orders.cancel', 'fp-abc'))
        ->toBe(ConsumedBindingGuard::digest('call-1', 'orders.cancel', 'fp-abc'))
        ->and(ConsumedBindingGuard::keyed('call-1', 'orders.cancel', 'fp-abc', 'secret-v1'))
        ->toBe(ConsumedBindingGuard::keyed('call-1', 'orders.cancel', 'fp-abc', 'secret-v1'));
});

it('separates the triple components unambiguously under the keyed scheme', function (): void {
    // Hashing each component to a fixed width means the tool-call/capability boundary cannot be
    // shifted to forge a collision: ('ab','c') and ('a','bc') derive different digests.
    $secret = 'secret-v1';

    expect(ConsumedBindingGuard::keyed('ab', 'c', 'fp', $secret))
        ->not->toBe(ConsumedBindingGuard::keyed('a', 'bc', 'fp', $secret))
        ->and(ConsumedBindingGuard::keyed('call-1', 'orders.cancel', 'fp', $secret))
        ->not->toBe(ConsumedBindingGuard::keyed('call-2', 'orders.cancel', 'fp', $secret))
        ->and(ConsumedBindingGuard::keyed('call-1', 'orders.cancel', 'fp', $secret))
        ->not->toBe(ConsumedBindingGuard::keyed('call-1', 'orders.refund', 'fp', $secret))
        ->and(ConsumedBindingGuard::keyed('call-1', 'orders.cancel', 'fp-1', $secret))
        ->not->toBe(ConsumedBindingGuard::keyed('call-1', 'orders.cancel', 'fp-2', $secret));
});

it('names the keyed algorithm it persists', function (): void {
    // The label that describes a keyed guard row (keyless rows persist a null algorithm). Pinned
    // here because later slices store and validate it against the derivation.
    expect(ConsumedBindingGuard::ALGORITHM_KEYED)->toBe('hmac-sha256');
});
