<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\ConsumedBindingGuardScheme;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryConsumedBindingGuardStore;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardCollision;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardSchemeMismatch;
use Fissible\Verdict\Exceptions\InvalidConsumedBindingGuardConfig;

// #514: keyed mode persists algorithm/key_version alongside each guard, and ADR 0039 says the matched
// row's metadata "describes how that guard was derived (validated after the match)." This realizes
// that: on a probe hit, the store returns the row's stored scheme (lookup, replacing the bool has),
// and the scheme validates it by RE-DERIVING the digest from the stored metadata and comparing — a
// self-consistency check. A row whose metadata contradicts its digest (tampered/corrupted without
// changing the digest) fails closed with ConsumedBindingGuardSchemeMismatch. Consistent rows refuse
// exactly as before. Secret-uniqueness across key versions keeps the attribution sound. Self-contained.

const AMV_TC = 'call-1';
const AMV_CAP = 'orders.cancel';
const AMV_FP = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const AMV_SECRET = 'a-guard-secret-of-at-least-thirty-two-chars'; // >= 32

function amvKeyless(): string
{
    return ConsumedBindingGuard::digest(AMV_TC, AMV_CAP, AMV_FP);
}

function amvKeyed(): string
{
    return ConsumedBindingGuard::keyed(AMV_TC, AMV_CAP, AMV_FP, AMV_SECRET);
}

function amvReceipt(): ApprovalReceipt
{
    return new ApprovalReceipt(
        id: 'receipt-'.bin2hex(random_bytes(6)), toolCallId: AMV_TC, capability: AMV_CAP, bindingFingerprint: AMV_FP,
        provenance: null, approvalContext: null, status: ApprovalReceiptStatus::Pending, reason: 'Confirm.',
        expiresAt: new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        approvedBy: null, approvedAt: null, rejectedBy: null, rejectedAt: null, consumedAt: null,
        createdAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        updatedAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
    );
}

function amvGuards(string $digest, ?string $algorithm, ?string $keyVersion): InMemoryConsumedBindingGuardStore
{
    $guards = new InMemoryConsumedBindingGuardStore;
    // Seed a guard row directly with the given (possibly tampered) metadata.
    $guards->remember($digest, new DateTimeImmutable('2026-09-01 12:01:00', new DateTimeZone('UTC')), $algorithm, $keyVersion);

    return $guards;
}

function amvKeyedStore(InMemoryConsumedBindingGuardStore $guards): InMemoryApprovalReceiptStore
{
    return new InMemoryApprovalReceiptStore(
        guards: $guards,
        scheme: new ConsumedBindingGuardScheme(['v1' => AMV_SECRET], activeKeyVersion: 'v1'),
    );
}

// ── after-match validation: a tampered row (metadata contradicts the digest) fails closed ─────────────

it('throws when a matched keyed digest is stored with keyless metadata (issue)', function (): void {
    // The digest is the keyed one, but the row claims keyless — re-deriving keyless does not reproduce it.
    $store = amvKeyedStore(amvGuards(amvKeyed(), null, null));

    expect(fn () => $store->issue(amvReceipt()))->toThrow(ConsumedBindingGuardSchemeMismatch::class);
});

it('throws when a matched keyless digest is stored with keyed metadata (issue)', function (): void {
    // The keyless candidate is always probed; here the keyless digest is stored claiming keyed v1.
    $store = amvKeyedStore(amvGuards(amvKeyless(), ConsumedBindingGuard::ALGORITHM_KEYED, 'v1'));

    expect(fn () => $store->issue(amvReceipt()))->toThrow(ConsumedBindingGuardSchemeMismatch::class);
});

it('throws on a tampered guard during consume() too', function (): void {
    // Issue+approve while the guard store is empty (issue does not trip), THEN inject a tampered guard
    // for this binding, THEN consume — consume's own probe must run the same after-match validation.
    $guards = new InMemoryConsumedBindingGuardStore;
    $store = amvKeyedStore($guards);

    $receipt = amvReceipt();
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->approve($receipt->id, AMV_TC, 'human', new DateTimeImmutable('2026-09-01 12:00:30', new DateTimeZone('UTC')))->outcome)
        ->toBe(ApprovalOutcome::Approved);

    // The keyed candidate digest is present but stored as keyless — re-derivation cannot reproduce it.
    $guards->remember(amvKeyed(), new DateTimeImmutable('2026-09-01 12:00:45', new DateTimeZone('UTC')), null, null);

    expect(fn () => $store->consume(AMV_TC, AMV_FP, new DateTimeImmutable('2026-09-01 12:01:00', new DateTimeZone('UTC'))))
        ->toThrow(ConsumedBindingGuardSchemeMismatch::class);
});

it('refuses consume() with ConsumedBindingGuardCollision when a CONSISTENT keyed guard is present', function (): void {
    // Positive control for the consume probe: a legitimate keyed replay (metadata matches the digest)
    // must still be refused as a collision — never a scheme mismatch, never silently allowed.
    $guards = new InMemoryConsumedBindingGuardStore;
    $store = amvKeyedStore($guards);

    $receipt = amvReceipt();
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->approve($receipt->id, AMV_TC, 'human', new DateTimeImmutable('2026-09-01 12:00:30', new DateTimeZone('UTC')))->outcome)
        ->toBe(ApprovalOutcome::Approved);

    // A consistent keyed guard under the active v1 scheme.
    $guards->remember(amvKeyed(), new DateTimeImmutable('2026-09-01 12:00:45', new DateTimeZone('UTC')), ConsumedBindingGuard::ALGORITHM_KEYED, 'v1');

    expect(fn () => $store->consume(AMV_TC, AMV_FP, new DateTimeImmutable('2026-09-01 12:01:00', new DateTimeZone('UTC'))))
        ->toThrow(ConsumedBindingGuardCollision::class);
});

it('throws when a keyless deployment meets a guard claiming a keyed scheme it cannot verify (issue)', function (): void {
    // No scheme configured, so only the keyless digest is probed; its row claims keyed v1, which a
    // keyless deployment has no key to re-derive — it fails closed rather than trusting the claim.
    $guards = amvGuards(amvKeyless(), ConsumedBindingGuard::ALGORITHM_KEYED, 'v1');
    $store = new InMemoryApprovalReceiptStore(guards: $guards); // no scheme = keyless

    expect(fn () => $store->issue(amvReceipt()))->toThrow(ConsumedBindingGuardSchemeMismatch::class);
});

it('throws when a matched row names a key version the active scheme no longer retains (issue)', function (): void {
    // The keyless candidate matches, but the row claims keyed 'v9' — a version not among the scheme's
    // retained keys, so it cannot be re-derived. Fail closed with a mismatch (never MissingKey, never skip).
    $guards = amvGuards(amvKeyless(), ConsumedBindingGuard::ALGORITHM_KEYED, 'v9');
    $store = amvKeyedStore($guards); // scheme retains only 'v1'

    expect(fn () => $store->issue(amvReceipt()))->toThrow(ConsumedBindingGuardSchemeMismatch::class);
});

it('throws when a matched row names an unknown algorithm the scheme cannot re-derive (issue)', function (): void {
    // 'sha512' is not a derivation this scheme knows; an unrecognisable algorithm cannot be verified.
    $guards = amvGuards(amvKeyless(), 'sha512', 'v1');
    $store = amvKeyedStore($guards);

    expect(fn () => $store->issue(amvReceipt()))->toThrow(ConsumedBindingGuardSchemeMismatch::class);
});

// ── a consistent row validates and refuses exactly as before (no false alarm) ─────────────────────────

it('refuses PreviouslyConsumed when a matched keyed guard is stored with matching keyed metadata', function (): void {
    $store = amvKeyedStore(amvGuards(amvKeyed(), ConsumedBindingGuard::ALGORITHM_KEYED, 'v1'));

    expect($store->issue(amvReceipt())->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed);
});

it('validates a guard stored under a retained non-active key by re-deriving THAT version', function (): void {
    // Active is v2, but this guard was written under v1 (still retained). Re-derivation must use the
    // STORED version (v1), not the active one — else a legitimate retained-key guard is a false mismatch.
    $secretV2 = 'a-different-secret-of-at-least-32-characters';
    $v1Digest = ConsumedBindingGuard::keyed(AMV_TC, AMV_CAP, AMV_FP, AMV_SECRET);
    $guards = amvGuards($v1Digest, ConsumedBindingGuard::ALGORITHM_KEYED, 'v1');
    $store = new InMemoryApprovalReceiptStore(
        guards: $guards,
        scheme: new ConsumedBindingGuardScheme(['v1' => AMV_SECRET, 'v2' => $secretV2], activeKeyVersion: 'v2'),
    );

    expect($store->issue(amvReceipt())->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed);
});

it('refuses PreviouslyConsumed when a matched keyless guard is stored with keyless metadata (no scheme)', function (): void {
    $guards = amvGuards(amvKeyless(), null, null);
    $store = new InMemoryApprovalReceiptStore(guards: $guards); // no scheme = keyless

    expect($store->issue(amvReceipt())->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed);
});

// ── secret-uniqueness keeps the attribution sound ─────────────────────────────────────────────────────

it('rejects a config where two key versions share a secret', function (): void {
    // If two versions share a secret they produce the same digest, so a match cannot attribute a
    // version — the after-match validation would be ambiguous. fromConfig fails closed.
    expect(fn () => ConsumedBindingGuardScheme::fromConfig([
        'active_key' => 'v1',
        'keys' => ['v1' => AMV_SECRET, 'v2' => AMV_SECRET],
    ]))->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('rejects a config where a non-adjacent pair of key versions shares a secret (3 keys)', function (): void {
    // v1 and v3 share a secret with a distinct v2 between them — a naive first-two / adjacent-only
    // check would miss it. The active key (v2) is itself distinct, so this isolates the pairwise scan.
    expect(fn () => ConsumedBindingGuardScheme::fromConfig([
        'active_key' => 'v2',
        'keys' => [
            'v1' => AMV_SECRET,
            'v2' => 'a-distinct-secret-of-at-least-thirty-two-chars',
            'v3' => AMV_SECRET,
        ],
    ]))->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('rejects a config where the active key duplicates a retained key secret', function (): void {
    expect(fn () => ConsumedBindingGuardScheme::fromConfig([
        'active_key' => 'v2',
        'keys' => ['v1' => AMV_SECRET, 'v2' => AMV_SECRET],
    ]))->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('accepts a config where each key version has a distinct secret', function (): void {
    $scheme = ConsumedBindingGuardScheme::fromConfig([
        'active_key' => 'v2',
        'keys' => ['v1' => AMV_SECRET, 'v2' => 'a-different-secret-of-at-least-32-characters'],
    ]);

    expect($scheme)->toBeInstanceOf(ConsumedBindingGuardScheme::class);
});
