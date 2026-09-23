<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ConsumedBindingGuard;

// The permanent replay guard is keyed by a one-way digest of the binding triple
// (toolCallId, capability, bindingFingerprint) — ADR 0039. Canonical form (§"digest"):
//   sha256_raw( sha256(toolCallId) . sha256(capability) . bindingFingerprint )
// The first two fields are pre-hashed to fixed width (hex) so no field boundary can shift; the
// binding fingerprint is appended UNCHANGED (it is already a fixed-width Verdict fingerprint). The
// result is the raw 32 bytes stored in the guard table's BINARY(32) key.

$fp = str_repeat('a', 64); // a valid 64-hex fingerprint

it('produces the exact prescribed digest (known-answer vector)', function () use ($fp): void {
    // Pins the algorithm and field ordering, not merely "some deterministic function": reversing the
    // fields, re-hashing the fingerprint, or truncating any part all change these bytes.
    expect(ConsumedBindingGuard::digest('call-1', 'orders.cancel', $fp))
        ->toBe(hex2bin('24ad55e065230cb327436a5af072599b67fe4a13620b152e0123308b918f5be1'));
});

it('is raw 32 bytes, not hex', function () use ($fp): void {
    expect(strlen(ConsumedBindingGuard::digest('call-1', 'orders.cancel', $fp)))->toBe(32);
});

it('is deterministic for the same binding triple', function () use ($fp): void {
    expect(ConsumedBindingGuard::digest('call-1', 'orders.cancel', $fp))
        ->toBe(ConsumedBindingGuard::digest('call-1', 'orders.cancel', $fp));
});

it('distinguishes swapped tool-call and capability', function () use ($fp): void {
    expect(ConsumedBindingGuard::digest('a', 'b', $fp))
        ->not->toBe(ConsumedBindingGuard::digest('b', 'a', $fp));
});

it('is sensitive to each field independently', function () use ($fp): void {
    $base = ConsumedBindingGuard::digest('call-1', 'orders.cancel', $fp);

    expect(ConsumedBindingGuard::digest('call-2', 'orders.cancel', $fp))->not->toBe($base)
        ->and(ConsumedBindingGuard::digest('call-1', 'orders.refund', $fp))->not->toBe($base)
        // fingerprint differing only at its final character
        ->and(ConsumedBindingGuard::digest('call-1', 'orders.cancel', str_repeat('a', 63).'b'))->not->toBe($base);
});

it('is injection-safe against plain and delimiter concatenation', function () use ($fp): void {
    // Plain concatenation would collide 'a'.'bc' with 'ab'.'c'.
    expect(ConsumedBindingGuard::digest('a', 'bc', $fp))
        ->not->toBe(ConsumedBindingGuard::digest('ab', 'c', $fp))
        // A single-delimiter scheme sha256(tool.'|'.cap.'|'.fp) would collide these; the canonical
        // per-field pre-hash must not.
        ->and(ConsumedBindingGuard::digest('a|b', 'c', $fp))
        ->not->toBe(ConsumedBindingGuard::digest('a', 'b|c', $fp));
});

it('preserves empty, whitespace, and NUL bytes in the fields', function () use ($fp): void {
    expect(ConsumedBindingGuard::digest('', 'x', $fp))->not->toBe(ConsumedBindingGuard::digest('x', '', $fp))
        ->and(ConsumedBindingGuard::digest("a\0b", 'c', $fp))->not->toBe(ConsumedBindingGuard::digest('ab', 'c', $fp))
        ->and(ConsumedBindingGuard::digest('a ', 'c', $fp))->not->toBe(ConsumedBindingGuard::digest('a', 'c', $fp));
});
