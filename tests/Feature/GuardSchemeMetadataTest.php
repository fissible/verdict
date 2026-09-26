<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\ConsumedBindingGuardScheme;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\DatabaseConsumedBindingGuardStore;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

// Slice 10c of ADR 0039 (keyed digest opt-in, scheme metadata persistence). remember() gains optional
// algorithm/key_version, and the database guard store writes them into the slice-9 columns (null under
// keyless). Both guard-WRITE paths — consume() and the pruneConsumedPayload() self-guarantee — record
// the guard under the ACTIVE scheme, so pruning a keyed binding never mints an offline-testable keyless
// guard. Keyless is unchanged (null metadata). Self-contained: only Pest.php globals + shipped stores.

const GM_TC = 'call-1';
const GM_CAP = 'orders.cancel';
const GM_FP = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // CHAR(64), Postgres pads shorter
const GM_GUARD_TABLE = 'verdict_consumed_binding_guards';

function gmConnection(): ConnectionInterface
{
    return app(DatabaseManager::class)->connection();
}

function gmGuardStore(): DatabaseConsumedBindingGuardStore
{
    return new DatabaseConsumedBindingGuardStore(gmConnection(), GM_GUARD_TABLE);
}

function gmTime(string $at = '2026-09-01 12:01:00'): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

/** Every guard row as {digest(raw bytes), algorithm, key_version}. */
function gmGuardRows(): array
{
    return gmConnection()->table(GM_GUARD_TABLE)->get()->map(fn (object $r): array => [
        'digest' => is_resource($r->digest) ? (string) stream_get_contents($r->digest) : (string) $r->digest,
        'algorithm' => $r->algorithm,
        'key_version' => $r->key_version,
    ])->all();
}

function gmReceipt(): ApprovalReceipt
{
    return new ApprovalReceipt(
        id: 'receipt-a',
        toolCallId: GM_TC,
        capability: GM_CAP,
        bindingFingerprint: GM_FP,
        provenance: null,
        approvalContext: null,
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm.',
        expiresAt: gmTime('2027-01-01 00:00:00'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: gmTime('2026-09-01 12:00:00'),
        updatedAt: gmTime('2026-09-01 12:00:00'),
    );
}

/** issue -> approve -> consume through a DB approval store with $scheme, sharing $guards. */
function gmConsume(?ConsumedBindingGuardScheme $scheme): ApprovalReceipt
{
    $store = new DatabaseApprovalReceiptStore(connection: gmConnection(), guards: gmGuardStore(), scheme: $scheme);
    $receipt = gmReceipt();
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->approve($receipt->id, GM_TC, 'human', gmTime('2026-09-01 12:00:30'))->outcome)->toBe(ApprovalOutcome::Approved);
    expect($store->consume(GM_TC, GM_FP, gmTime())->outcome)->toBe(ApprovalOutcome::Consumed);

    return $receipt;
}

beforeEach(function (): void {
    config()->set('verdict.approvals.consumed_binding_guard', null);
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    foreach ([verdictTable('approvals'), GM_GUARD_TABLE, 'verdict_binding_admission_locks'] as $table) {
        $schema->dropIfExists($table);
    }
    foreach ([
        'create_verdict_approval_receipts_table.php.stub',
        'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        'add_approval_context_to_verdict_approval_receipts_table.php.stub',
        'create_verdict_consumed_binding_guards_table.php.stub',
        'add_scheme_to_verdict_consumed_binding_guards_table.php.stub',
        'create_verdict_binding_admission_locks_table.php.stub',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    foreach ([verdictTable('approvals'), GM_GUARD_TABLE, 'verdict_binding_admission_locks'] as $table) {
        $schema->dropIfExists($table);
    }
});

// ── the database guard store persists scheme metadata ──────────────────────────────────────────────

it('persists the algorithm and key version passed to remember()', function (): void {
    gmGuardStore()->remember(str_repeat('a', 32), gmTime(), 'hmac-sha256', 'v1');

    expect(gmGuardRows())->toBe([
        ['digest' => str_repeat('a', 32), 'algorithm' => 'hmac-sha256', 'key_version' => 'v1'],
    ]);
});

it('records null algorithm and key version for a keyless remember() (the default)', function (): void {
    gmGuardStore()->remember(str_repeat('b', 32), gmTime());

    expect(gmGuardRows())->toBe([
        ['digest' => str_repeat('b', 32), 'algorithm' => null, 'key_version' => null],
    ]);
});

it('widens the ConsumedBindingGuardStore::remember() contract to carry the scheme metadata', function (): void {
    // The metadata must ride on the interface, not just the concrete database store: a third-party
    // store typed to the contract has to receive algorithm/key_version too, or keyed deployments
    // that swap the store silently drop the scheme and leak a keyless-equivalent guard.
    $parameters = (new ReflectionMethod(ConsumedBindingGuardStore::class, 'remember'))->getParameters();
    $names = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $parameters);

    expect($names)->toBe(['digest', 'consumedAt', 'algorithm', 'keyVersion'])
        // Optional, so the keyless callers that pass two arguments keep working.
        ->and(array_filter($parameters, static fn (ReflectionParameter $p): bool => $p->getName() === 'algorithm' && $p->isOptional()))->toHaveCount(1)
        ->and(array_filter($parameters, static fn (ReflectionParameter $p): bool => $p->getName() === 'keyVersion' && $p->isOptional()))->toHaveCount(1);
});

// ── consume() records the guard with its scheme metadata ───────────────────────────────────────────

it('consume records the keyed digest with its algorithm and key version', function (): void {
    gmConsume(new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v1'));

    expect(gmGuardRows())->toBe([[
        'digest' => ConsumedBindingGuard::keyed(GM_TC, GM_CAP, GM_FP, 'secret-1'),
        'algorithm' => 'hmac-sha256',
        'key_version' => 'v1',
    ]]);
});

it('consume records a keyless guard with null metadata when no scheme is set', function (): void {
    gmConsume(null);

    expect(gmGuardRows())->toBe([[
        'digest' => ConsumedBindingGuard::digest(GM_TC, GM_CAP, GM_FP),
        'algorithm' => null,
        'key_version' => null,
    ]]);
});

it('records the actual active key version, not a hardcoded one', function (): void {
    // Two retained versions with v2 active: the persisted key_version must plumb
    // DerivedGuard->keyVersion, so an impl that wrote a literal 'v1' would fail here.
    gmConsume(new ConsumedBindingGuardScheme(['v1' => 'secret-1', 'v2' => 'secret-2'], activeKeyVersion: 'v2'));

    expect(gmGuardRows())->toBe([[
        'digest' => ConsumedBindingGuard::keyed(GM_TC, GM_CAP, GM_FP, 'secret-2'),
        'algorithm' => 'hmac-sha256',
        'key_version' => 'v2',
    ]]);
});

// ── the prune self-guarantee records under the ACTIVE scheme, not keyless ───────────────────────────

it('re-guarantees the KEYED guard (with metadata) when pruning a keyed binding, minting no keyless guard', function (): void {
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v1');
    gmConsume($scheme);

    // Clear the guard so prune's self-guarantee re-creates it (prune re-guarantees the guard for
    // every consumed row before deleting the payload).
    gmConnection()->table(GM_GUARD_TABLE)->delete();
    expect(gmGuardRows())->toBe([]);

    $store = new DatabaseApprovalReceiptStore(connection: gmConnection(), guards: gmGuardStore(), scheme: $scheme);
    expect($store->pruneConsumedPayload(gmTime('2027-01-01 00:00:00')))->toBe(1);

    // The re-guaranteed guard is the KEYED digest with its metadata — never an offline-testable
    // keyless guard for a keyed binding.
    expect(gmGuardRows())->toBe([[
        'digest' => ConsumedBindingGuard::keyed(GM_TC, GM_CAP, GM_FP, 'secret-1'),
        'algorithm' => 'hmac-sha256',
        'key_version' => 'v1',
    ]]);
});

it('re-guarantees a keyless guard with null metadata when pruning a keyless binding', function (): void {
    gmConsume(null);
    gmConnection()->table(GM_GUARD_TABLE)->delete();

    $store = new DatabaseApprovalReceiptStore(connection: gmConnection(), guards: gmGuardStore(), scheme: null);
    expect($store->pruneConsumedPayload(gmTime('2027-01-01 00:00:00')))->toBe(1);

    expect(gmGuardRows())->toBe([[
        'digest' => ConsumedBindingGuard::digest(GM_TC, GM_CAP, GM_FP),
        'algorithm' => null,
        'key_version' => null,
    ]]);
});
