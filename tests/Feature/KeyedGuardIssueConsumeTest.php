<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\ConsumedBindingGuardScheme;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryConsumedBindingGuardStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardCollision;
use Fissible\Verdict\Exceptions\MissingConsumedBindingGuardKey;
use Illuminate\Database\DatabaseManager;

// Slice 10b of ADR 0039 (keyed digest opt-in, the issue()/consume() wiring). Both approval stores gain
// an optional ConsumedBindingGuardScheme. issue() consults the guard only when NO receipt row exists
// for the binding (a present row takes the existing-row outcome) — i.e. after the payload was pruned —
// and refuses if ANY candidate digest (keyless plus one per retained keyed version) has a guard.
// consume() records the guard under the ACTIVE scheme. With no scheme the behaviour is byte-for-byte
// the keyless default. So issue()'s probing is proven by SEEDING a guard with no row (as the sibling
// IssueConsultsGuardTest does), and consume()'s write is proven separately. A missing key fails closed
// at issue() and consume(). Persisting the algorithm/key_version columns needs a remember() interface
// change and is the next slice; this one records the active digest. Self-contained: Pest.php globals.

const KG_TC = 'call-1';
const KG_CAP = 'orders.cancel';
const KG_FP = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // 64-char fixed-width fingerprint: binding_fingerprint is CHAR(64), which Postgres space-pads
const KG_GUARD_TABLE = 'verdict_consumed_binding_guards';

function kgTime(string $at): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

function kgReceipt(string $suffix): ApprovalReceipt
{
    return new ApprovalReceipt(
        id: 'receipt-'.$suffix,
        toolCallId: KG_TC,
        capability: KG_CAP,
        bindingFingerprint: KG_FP,
        provenance: null,
        approvalContext: null,
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm.',
        expiresAt: kgTime('2027-01-01 00:00:00'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: kgTime('2026-09-01 12:00:00'),
        updatedAt: kgTime('2026-09-01 12:00:00'),
    );
}

function kgStore(string $driver, ConsumedBindingGuardStore $guards, ?ConsumedBindingGuardScheme $scheme): ApprovalReceiptStore
{
    return $driver === 'database'
        ? new DatabaseApprovalReceiptStore(connection: app(DatabaseManager::class)->connection(), guards: $guards, scheme: $scheme)
        : new InMemoryApprovalReceiptStore(guards: $guards, scheme: $scheme);
}

/** Seed a guard for a digest with NO receipt row — the post-prune state issue() consults the guard in. */
function kgSeedGuard(ConsumedBindingGuardStore $guards, string $digest): void
{
    $guards->remember($digest, kgTime('2026-09-01 12:01:00'));
}

/** issue -> approve -> consume a fresh binding through $store, asserting each step. */
function kgConsume(ApprovalReceiptStore $store, string $suffix): ApprovalReceipt
{
    $receipt = kgReceipt($suffix);
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->approve($receipt->id, KG_TC, 'human', kgTime('2026-09-01 12:00:30'))->outcome)->toBe(ApprovalOutcome::Approved);
    expect($store->consume(KG_TC, KG_FP, kgTime('2026-09-01 12:01:00'))->outcome)->toBe(ApprovalOutcome::Consumed);

    return $receipt;
}

dataset('approval stores', ['database', 'in-memory']);

beforeEach(function (): void {
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.approvals.connection', null);
    config()->set('verdict.approvals.consumed_binding_guard', null);

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    foreach ([verdictTable('approvals'), KG_GUARD_TABLE, 'verdict_binding_admission_locks'] as $table) {
        $schema->dropIfExists($table);
    }
    foreach ([
        'create_verdict_approval_receipts_table.php.stub',
        'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        'add_approval_context_to_verdict_approval_receipts_table.php.stub',
        'create_verdict_consumed_binding_guards_table.php.stub',
        'create_verdict_binding_admission_locks_table.php.stub',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    foreach ([verdictTable('approvals'), KG_GUARD_TABLE, 'verdict_binding_admission_locks'] as $table) {
        $schema->dropIfExists($table);
    }
});

// ── issue() probes the candidate digests (guard seeded, no receipt row) ────────────────────────────

it('refuses a fresh binding whose keyless guard is present, with no scheme (the shipped default)', function (string $driver): void {
    $guards = new InMemoryConsumedBindingGuardStore;
    kgSeedGuard($guards, ConsumedBindingGuard::digest(KG_TC, KG_CAP, KG_FP));

    expect(kgStore($driver, $guards, null)->issue(kgReceipt('replay'))->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed);
})->with('approval stores');

it('refuses a fresh binding whose ACTIVE keyed guard is present', function (string $driver): void {
    $guards = new InMemoryConsumedBindingGuardStore;
    kgSeedGuard($guards, ConsumedBindingGuard::keyed(KG_TC, KG_CAP, KG_FP, 'secret-1'));
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v1');

    expect(kgStore($driver, $guards, $scheme)->issue(kgReceipt('replay'))->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed);
})->with('approval stores');

it('still refuses a keyless-era guard after migrating to keyed (the keyless candidate is always probed)', function (string $driver): void {
    $guards = new InMemoryConsumedBindingGuardStore;
    kgSeedGuard($guards, ConsumedBindingGuard::digest(KG_TC, KG_CAP, KG_FP)); // written while keyless
    $keyed = new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v1');

    expect(kgStore($driver, $guards, $keyed)->issue(kgReceipt('replay'))->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed);
})->with('approval stores');

it('still refuses a retired-key guard after rotating the active key (the retired version is still probed)', function (string $driver): void {
    $guards = new InMemoryConsumedBindingGuardStore;
    kgSeedGuard($guards, ConsumedBindingGuard::keyed(KG_TC, KG_CAP, KG_FP, 'secret-1')); // written under v1
    $rotated = new ConsumedBindingGuardScheme(['v1' => 'secret-1', 'v2' => 'secret-2'], activeKeyVersion: 'v2');

    expect(kgStore($driver, $guards, $rotated)->issue(kgReceipt('replay'))->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed);
})->with('approval stores');

it('issues a binding with no guard under a keyed scheme (does not spuriously refuse)', function (string $driver): void {
    $guards = new InMemoryConsumedBindingGuardStore;
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v1');

    expect(kgStore($driver, $guards, $scheme)->issue(kgReceipt('fresh'))->outcome)->toBe(ApprovalOutcome::Issued);
})->with('approval stores');

it('does not refuse a keyed-only guard when running keyless (only the keyless candidate is probed)', function (string $driver): void {
    // A guard written under a key this (keyless) store does not know must NOT be found — a keyless
    // probe sees only the keyless digest. Proves candidates() is scheme-derived, not "check everything".
    $guards = new InMemoryConsumedBindingGuardStore;
    kgSeedGuard($guards, ConsumedBindingGuard::keyed(KG_TC, KG_CAP, KG_FP, 'secret-1'));

    expect(kgStore($driver, $guards, null)->issue(kgReceipt('fresh'))->outcome)->toBe(ApprovalOutcome::Issued);
})->with('approval stores');

// ── consume() records the guard under the active scheme ────────────────────────────────────────────

it('records the keyless guard on consume with no scheme', function (string $driver): void {
    $guards = new InMemoryConsumedBindingGuardStore;
    kgConsume(kgStore($driver, $guards, null), 'a');

    expect($guards->has(ConsumedBindingGuard::digest(KG_TC, KG_CAP, KG_FP)))->toBeTrue();
})->with('approval stores');

it('records the ACTIVE keyed guard on consume, not the keyless one', function (string $driver): void {
    $guards = new InMemoryConsumedBindingGuardStore;
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v1');
    kgConsume(kgStore($driver, $guards, $scheme), 'a');

    expect($guards->has(ConsumedBindingGuard::keyed(KG_TC, KG_CAP, KG_FP, 'secret-1')))->toBeTrue()
        ->and($guards->has(ConsumedBindingGuard::digest(KG_TC, KG_CAP, KG_FP)))->toBeFalse();
})->with('approval stores');

it('rejects consume() when a guard exists under a NON-active candidate (probes every candidate, not just the active one)', function (string $driver): void {
    // Guard the binding under the retired key v1 while v2 is active. consume() must probe the full
    // candidate set for the collision, not only the active digest — else a rolling deploy could
    // double-consume a binding whose guard was written under a now-retired key.
    $guards = new InMemoryConsumedBindingGuardStore;
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1', 'v2' => 'secret-2'], activeKeyVersion: 'v2');
    $store = kgStore($driver, $guards, $scheme);

    $receipt = kgReceipt('c');
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->approve($receipt->id, KG_TC, 'human', kgTime('2026-09-01 12:00:30'))->outcome)->toBe(ApprovalOutcome::Approved);

    // Seed the collision AFTER approval (seeding before would make issue() refuse), under the
    // non-active v1 candidate.
    kgSeedGuard($guards, ConsumedBindingGuard::keyed(KG_TC, KG_CAP, KG_FP, 'secret-1'));

    expect(fn () => $store->consume(KG_TC, KG_FP, kgTime('2026-09-01 12:01:00')))
        ->toThrow(ConsumedBindingGuardCollision::class);
})->with('approval stores');

// ── fail closed on a missing key ─────────────────────────────────────────────────────────────────

it('fails closed at issue() when a retained key has no secret, minting no receipt', function (string $driver): void {
    $guards = new InMemoryConsumedBindingGuardStore;
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1', 'v2' => ''], activeKeyVersion: 'v1');
    $store = kgStore($driver, $guards, $scheme);

    $receipt = kgReceipt('fail');
    expect(fn () => $store->issue($receipt))->toThrow(MissingConsumedBindingGuardKey::class);
    expect($store->find($receipt->id))->toBeNull(); // refused rather than minted
})->with('approval stores');

it('fails closed at consume() when the active key is unavailable, recording nothing and leaving the receipt approved', function (string $driver): void {
    $guards = new InMemoryConsumedBindingGuardStore;

    // The active version 'v2' is not among the retained keys: candidates() (keyless + v1) still
    // resolves, so issue()/approve() and consume()'s collision probe proceed, but active() cannot
    // derive the write digest and throws — modelling a receipt approved before its active secret
    // went missing. One store instance, so it applies to the in-memory store too (which shares no
    // receipt state across instances).
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v2');
    $store = kgStore($driver, $guards, $scheme);

    $receipt = kgReceipt('c');
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->approve($receipt->id, KG_TC, 'human', kgTime('2026-09-01 12:00:30'))->outcome)->toBe(ApprovalOutcome::Approved);

    expect(fn () => $store->consume(KG_TC, KG_FP, kgTime('2026-09-01 12:01:00')))->toThrow(MissingConsumedBindingGuardKey::class);

    // Nothing recorded under any scheme, and the receipt is untouched (no partial consume).
    expect($guards->has(ConsumedBindingGuard::keyed(KG_TC, KG_CAP, KG_FP, 'secret-1')))->toBeFalse()
        ->and($guards->has(ConsumedBindingGuard::digest(KG_TC, KG_CAP, KG_FP)))->toBeFalse()
        ->and($store->find($receipt->id)->status)->toBe(ApprovalReceiptStatus::Approved);
})->with('approval stores');

// ── the service provider builds the scheme from config ─────────────────────────────────────────────

/** The raw digest bytes of every guard row on the default connection. @return list<string> */
function kgGuardDigests(): array
{
    return app(DatabaseManager::class)->connection()->table(KG_GUARD_TABLE)->get()
        ->map(fn (object $row): string => is_resource($row->digest) ? (string) stream_get_contents($row->digest) : (string) $row->digest)
        ->all();
}

it('builds a KEYED scheme from config so consume records the keyed digest', function (): void {
    config()->set('verdict.approvals.consumed_binding_guard.active_key', 'v1');
    config()->set('verdict.approvals.consumed_binding_guard.keys', ['v1' => 'container-secret-1']);
    app()->forgetInstance(ApprovalReceiptStore::class);

    kgConsume(app(ApprovalReceiptStore::class), 'a');

    // Decisive: the persisted guard is the KEYED digest, not the keyless one — so the SP honoured
    // the keyed config rather than falling back to keyless.
    expect(kgGuardDigests())->toBe([ConsumedBindingGuard::keyed(KG_TC, KG_CAP, KG_FP, 'container-secret-1')])
        ->and(kgGuardDigests())->not->toContain(ConsumedBindingGuard::digest(KG_TC, KG_CAP, KG_FP));
});

it('defaults to a keyless scheme when no keyed config is set', function (): void {
    config()->set('verdict.approvals.consumed_binding_guard', null);
    app()->forgetInstance(ApprovalReceiptStore::class);

    kgConsume(app(ApprovalReceiptStore::class), 'a');

    expect(kgGuardDigests())->toBe([ConsumedBindingGuard::digest(KG_TC, KG_CAP, KG_FP)]);
});
