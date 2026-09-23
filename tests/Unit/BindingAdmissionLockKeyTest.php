<?php

declare(strict_types=1);

use Fissible\Verdict\Support\BindingAdmission;

// The coarse-pair admission lock (ADR 0039) is keyed by a deterministic SIGNED 64-bit integer
// derived from (toolCallId, bindingFingerprint): the value handed to Postgres pg_advisory_xact_lock
// and used as the MySQL/MariaDB lock-row key. Canonical form:
//   unpack signed-64-big-endian( first 8 bytes of sha256_raw(sha256(toolCallId).sha256(bindingFingerprint)) )
// The per-field pre-hash fixes the field boundary; capability is deliberately NOT an input, so a
// consume() (which never receives capability) and an issue() serialize on the same pair.

$fp = str_repeat('a', 64);

it('derives the exact prescribed lock key (known-answer vector)', function () use ($fp): void {
    // Pins the algorithm and byte order, not merely "some deterministic int": a different digest,
    // little-endian read, or a wider/narrower slice all change this value.
    expect(BindingAdmission::lockKey('call-1', $fp))->toBe(2301213462760360818);
});

it('is deterministic for the same pair', function () use ($fp): void {
    expect(BindingAdmission::lockKey('call-1', $fp))
        ->toBe(BindingAdmission::lockKey('call-1', $fp));
});

it('uses the full signed 64-bit space, not a folded-positive key', function (): void {
    // Postgres advisory keys are signed bigints; masking to positive (abs / >>1) would halve the
    // space and collide twice as often. This pair hashes into the negative half.
    expect(BindingAdmission::lockKey('neg-probe-3', 'fp'))->toBe(-2274087212235053863);
});

it('distinguishes the tool-call id and the fingerprint independently', function () use ($fp): void {
    $base = BindingAdmission::lockKey('call-1', $fp);

    expect(BindingAdmission::lockKey('call-2', $fp))->not->toBe($base)
        ->and(BindingAdmission::lockKey('call-1', str_repeat('a', 63).'b'))->not->toBe($base);
});

it('is injection-safe against plain concatenation of the pair', function (): void {
    // Plain concatenation would collide ('a'.'bc') with ('ab'.'c'); the per-field pre-hash must not.
    expect(BindingAdmission::lockKey('a', 'bc'))
        ->not->toBe(BindingAdmission::lockKey('ab', 'c'));
});
