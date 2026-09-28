<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\ConsumedBindingGuardScheme;
use Fissible\Verdict\Approvals\DerivedGuard;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryConsumedBindingGuardStore;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardSchemeDowngraded;

// Follow-up to ADR 0039 / #514: a keyed -> keyless DOWNGRADE must not silently reopen the replay
// window. Keyed guards persist their derivation (algorithm/key_version); a keyless-effective scheme
// (no config, or retained keys emptied) cannot re-derive them, so guardCandidates() would never
// probe those rows and a consumed-and-pruned binding would re-issue. That is the exact outcome
// ADR 0039 exists to prevent. The store therefore FAILS CLOSED (ConsumedBindingGuardSchemeDowngraded)
// on any issue()/consume() once it observes a schemed guard row under a keyless-effective scheme —
// the check is a single, memoised existence query, not a per-request cost. A deployment that never
// used keyed mode (no schemed rows) is unaffected, and retained-keys-with-null-active still probes.
// The consume-path downgrade is a CROSS-INSTANCE event (a persisted approved receipt meets a new
// keyless-scheme store instance); it is covered against the database store in
// KeyedGuardConfigHardeningTest, not here, because the in-memory receipt store cannot carry an
// approved receipt across the config change. Scope boundary: PARTIAL key retirement (drop a retained
// version while others remain) is a separate, analogous gap not covered here — the scheme is still
// keyed-effective, so this check does not fire. Self-contained: only Pest.php globals.

const GD_TC = 'call-1';
const GD_CAP = 'orders.cancel';
const GD_FP = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const GD_SECRET = 'a-guard-secret-of-at-least-thirty-two-chars'; // >= 32

function gdKeyed(): string
{
    return ConsumedBindingGuard::keyed(GD_TC, GD_CAP, GD_FP, GD_SECRET);
}

function gdKeyless(): string
{
    return ConsumedBindingGuard::digest(GD_TC, GD_CAP, GD_FP);
}

function gdAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-09-01 12:01:00', new DateTimeZone('UTC'));
}

function gdReceipt(string $suffix): ApprovalReceipt
{
    // A fresh, unrelated binding (its own fingerprint) with no receipt row, so issue() reaches the
    // guard-probe block where the downgrade check lives.
    return new ApprovalReceipt(
        id: 'receipt-'.$suffix,
        toolCallId: 'call-'.$suffix,
        capability: GD_CAP,
        bindingFingerprint: hash('sha256', $suffix),
        provenance: null, approvalContext: null, status: ApprovalReceiptStatus::Pending, reason: 'Confirm.',
        expiresAt: new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        approvedBy: null, approvedAt: null, rejectedBy: null, rejectedAt: null, consumedAt: null,
        createdAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        updatedAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
    );
}

/** Seed a keyed guard row (as a real consume() under key v1 would have written it). */
function gdKeyedGuardStore(): InMemoryConsumedBindingGuardStore
{
    $guards = new InMemoryConsumedBindingGuardStore;
    $guards->remember(gdKeyed(), gdAt(), ConsumedBindingGuard::ALGORITHM_KEYED, 'v1');

    return $guards;
}

// ── fail closed: a schemed guard under a keyless-effective scheme ──────────────────────────────────

it('fails closed on issue() when a keyed guard exists but the scheme was removed (null)', function (): void {
    $store = new InMemoryApprovalReceiptStore(guards: gdKeyedGuardStore(), scheme: null);

    expect(fn () => $store->issue(gdReceipt('fresh')))->toThrow(ConsumedBindingGuardSchemeDowngraded::class);
});

it('names restoring the keys as the remedy in the downgrade refusal', function (): void {
    // The refusal is a total approval outage; the message must state how to recover, not only what it
    // refused — so an operator reading it knows to restore the retained keys.
    $store = new InMemoryApprovalReceiptStore(guards: gdKeyedGuardStore(), scheme: null);

    try {
        $store->issue(gdReceipt('fresh'));
        throw new RuntimeException('expected a downgrade refusal');
    } catch (ConsumedBindingGuardSchemeDowngraded $e) {
        expect(strtolower($e->getMessage()))->toContain('restore');
    }
});

it('fails closed on issue() when the scheme was reverted to the empty-keys default', function (): void {
    // SHAPE B: config reverted to the shipped default [active_key => null, keys => []] — a non-null
    // scheme with no keys is still keyless-effective, so it must trip exactly like a removed config.
    $scheme = ConsumedBindingGuardScheme::fromConfig(['active_key' => null, 'keys' => []]);
    $store = new InMemoryApprovalReceiptStore(guards: gdKeyedGuardStore(), scheme: $scheme);

    expect(fn () => $store->issue(gdReceipt('fresh')))->toThrow(ConsumedBindingGuardSchemeDowngraded::class);
});

it('queries the guard store for a schemed guard at most once per store instance (memoised)', function (): void {
    // The downgrade check is a per-instance existence query, not a per-request cost: a legitimate
    // keyless deployment (no schemed guard) must not re-query on every issue(). The spy has no guards,
    // so issuance mints; the assertion is on how many times the store asked whether one is schemed.
    $guards = new class implements ConsumedBindingGuardStore
    {
        public int $schemedGuardQueries = 0;

        public function lookup(string $digest): ?DerivedGuard
        {
            return null;
        }

        public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void {}

        public function hasSchemedGuard(): bool
        {
            $this->schemedGuardQueries++;

            return false;
        }
    };
    $store = new InMemoryApprovalReceiptStore(guards: $guards, scheme: null);

    expect($store->issue(gdReceipt('one'))->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($store->issue(gdReceipt('two'))->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($store->issue(gdReceipt('three'))->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($guards->schemedGuardQueries)->toBe(1);
});

// ── controls: no false trip ─────────────────────────────────────────────────────────────────────────

it('does not trip for a keyless deployment that never used keyed mode', function (): void {
    // Only a keyless guard is present; a null scheme with no schemed rows is a legitimate keyless
    // deployment — issue() refuses the replayed binding as PreviouslyConsumed, and mints a fresh one.
    $guards = new InMemoryConsumedBindingGuardStore;
    $guards->remember(gdKeyless(), gdAt());
    $store = new InMemoryApprovalReceiptStore(guards: $guards, scheme: null);

    $replay = new ApprovalReceipt(
        id: 'receipt-replay', toolCallId: GD_TC, capability: GD_CAP, bindingFingerprint: GD_FP,
        provenance: null, approvalContext: null, status: ApprovalReceiptStatus::Pending, reason: 'Confirm.',
        expiresAt: new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        approvedBy: null, approvedAt: null, rejectedBy: null, rejectedAt: null, consumedAt: null,
        createdAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        updatedAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
    );

    expect($store->issue($replay)->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed)
        ->and($store->issue(gdReceipt('fresh'))->outcome)->toBe(ApprovalOutcome::Issued);
});

it('does not trip when keys are retained with a null active version (the guard is still probed)', function (): void {
    // SHAPE C control: keys retained but no active version is NOT keyless-effective — the keyed
    // candidate is still probed, so the replayed binding refuses as PreviouslyConsumed, no downgrade.
    $scheme = new ConsumedBindingGuardScheme(['v1' => GD_SECRET], activeKeyVersion: null);
    $store = new InMemoryApprovalReceiptStore(guards: gdKeyedGuardStore(), scheme: $scheme);

    $replay = new ApprovalReceipt(
        id: 'receipt-replay', toolCallId: GD_TC, capability: GD_CAP, bindingFingerprint: GD_FP,
        provenance: null, approvalContext: null, status: ApprovalReceiptStatus::Pending, reason: 'Confirm.',
        expiresAt: new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        approvedBy: null, approvedAt: null, rejectedBy: null, rejectedAt: null, consumedAt: null,
        createdAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        updatedAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
    );

    expect($store->issue($replay)->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed);
});

it('does not trip when the active keyed scheme still matches the guard', function (): void {
    $scheme = new ConsumedBindingGuardScheme(['v1' => GD_SECRET], activeKeyVersion: 'v1');
    $store = new InMemoryApprovalReceiptStore(guards: gdKeyedGuardStore(), scheme: $scheme);

    expect($store->issue(gdReceipt('fresh'))->outcome)->toBe(ApprovalOutcome::Issued);
});
