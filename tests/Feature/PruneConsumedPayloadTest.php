<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\DatabaseConsumedBindingGuardStore;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Fissible\Verdict\Contracts\PrunesConsumedApprovalPayload;
use Fissible\Verdict\Support\BindingAdmission;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

// Slice 6 of ADR 0039: pruneConsumedPayload() deletes CONSUMED receipt payloads with consumed_at <=
// $consumedBefore (inclusive), but only after GUARANTEEING the permanent guard exists for the binding —
// self-guarding, one admission-locked transaction per row, never touching a row that never admitted an
// execution, and fail-closed (throws) without a guard store rather than strand a binding. Retention is
// clocked on consumed_at. The --consumed-days command and the SQLite BEGIN IMMEDIATE arm are later
// slices. Self-contained: only Pest.php globals.

const PCP_GUARD_TABLE = 'verdict_consumed_binding_guards';

// A generous expiry so a receipt can be consumed at any test instant without expiring first.
function pcpTime(string $at): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

function pcpReceipt(string $toolCallId, string $capability, string $fingerprint): ApprovalReceipt
{
    return new ApprovalReceipt(
        id: bin2hex(random_bytes(16)),
        toolCallId: $toolCallId,
        capability: $capability,
        bindingFingerprint: $fingerprint,
        provenance: null,
        approvalContext: null,
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm.',
        expiresAt: pcpTime('2027-01-01 00:00:00'), // far future: never the thing that governs these tests
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: pcpTime('2026-08-01 12:00:00'),
        updatedAt: pcpTime('2026-08-01 12:00:00'),
    );
}

/**
 * A guard store that records remembers and answers has() from them. It can be told to (a) assert the
 * receipt still exists at remember() time (guarantee-before-delete) and (b) throw from remember().
 */
function pcpGuards(): ConsumedBindingGuardStore
{
    return new class implements ConsumedBindingGuardStore
    {
        /** @var list<string> */
        public array $remembered = [];

        public bool $throwFromRemember = false;

        /** @var null|Closure(string): void */
        public ?Closure $onRemember = null;

        /** @var null|Closure(string): void */
        public ?Closure $onHas = null;

        public function has(string $digest): bool
        {
            if ($this->onHas !== null) {
                ($this->onHas)($digest);
            }

            return in_array($digest, $this->remembered, true);
        }

        public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void
        {
            if ($this->onRemember !== null) {
                ($this->onRemember)($digest);
            }

            if ($this->throwFromRemember) {
                throw new RuntimeException('guard write failed');
            }

            if (! in_array($digest, $this->remembered, true)) {
                $this->remembered[] = $digest;
            }
        }
    };
}

function pcpStore(string $driver, ?ConsumedBindingGuardStore $guards): ApprovalReceiptStore
{
    return $driver === 'database'
        ? new DatabaseApprovalReceiptStore(connection: app(DatabaseManager::class)->connection(), guards: $guards)
        : new InMemoryApprovalReceiptStore(guards: $guards);
}

/** Issue -> approve -> consume at $consumedAt, asserting each step; returns the receipt. */
function pcpSeedConsumed(ApprovalReceiptStore $store, string $toolCallId, string $capability, string $fingerprint, string $consumedAt): ApprovalReceipt
{
    $receipt = pcpReceipt($toolCallId, $capability, $fingerprint);
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->approve($receipt->id, $toolCallId, 'human', pcpTime('2026-08-01 12:00:30'))->outcome)->toBe(ApprovalOutcome::Approved);
    expect($store->consume($toolCallId, $fingerprint, pcpTime($consumedAt))->outcome)->toBe(ApprovalOutcome::Consumed);

    return $receipt;
}

function pcpDigest(string $toolCallId, string $capability, string $fingerprint): string
{
    return ConsumedBindingGuard::digest($toolCallId, $capability, $fingerprint);
}

dataset('prune drivers', ['database', 'in-memory']);

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach ([verdictTable('approvals'), PCP_GUARD_TABLE, 'verdict_binding_admission_locks'] as $table) {
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

    foreach ([verdictTable('approvals'), PCP_GUARD_TABLE, 'verdict_binding_admission_locks'] as $table) {
        $schema->dropIfExists($table);
    }
});

it('both stores declare the additive prune-consumed marker', function (string $driver): void {
    expect(pcpStore($driver, pcpGuards()))->toBeInstanceOf(PrunesConsumedApprovalPayload::class);
})->with('prune drivers');

it('prunes only the consumed rows past retention, guarantees their guards, and returns the exact count', function (string $driver): void {
    $guards = pcpGuards();
    $store = pcpStore($driver, $guards);

    // Two consumed rows past the cutoff, one consumed within it, plus pending/approved/rejected.
    $pastA = pcpSeedConsumed($store, 'call-a', 'orders.cancel', hash('sha256', 'a'), '2026-08-01 12:05:00');
    $pastB = pcpSeedConsumed($store, 'call-b', 'orders.refund', hash('sha256', 'b'), '2026-08-02 09:00:00');
    $recent = pcpSeedConsumed($store, 'call-c', 'orders.cancel', hash('sha256', 'c'), '2026-08-20 09:00:00');

    $pending = pcpReceipt('call-p', 'orders.cancel', hash('sha256', 'p'));
    $store->issue($pending);
    $approved = pcpReceipt('call-ap', 'orders.cancel', hash('sha256', 'ap'));
    $store->issue($approved);
    $store->approve($approved->id, 'call-ap', 'human', pcpTime('2026-08-01 12:00:30'));

    /** @var PrunesConsumedApprovalPayload $store */
    $pruned = $store->pruneConsumedPayload(pcpTime('2026-08-05 00:00:00'));

    expect($pruned)->toBe(2)
        ->and($store->find($pastA->id))->toBeNull()
        ->and($store->find($pastB->id))->toBeNull()
        ->and($store->find($recent->id))->not->toBeNull()   // consumed within retention: kept
        ->and($store->find($pending->id))->not->toBeNull()  // never admitted an execution: kept
        ->and($store->find($approved->id))->not->toBeNull()
        // guards for the two pruned bindings survive; nothing for the kept ones was disturbed.
        ->and($guards->has(pcpDigest('call-a', 'orders.cancel', hash('sha256', 'a'))))->toBeTrue()
        ->and($guards->has(pcpDigest('call-b', 'orders.refund', hash('sha256', 'b'))))->toBeTrue();

    // A second sweep finds nothing more.
    expect($store->pruneConsumedPayload(pcpTime('2026-08-05 00:00:00')))->toBe(0);
})->with('prune drivers');

it('clocks retention on consumed_at, inclusively, not created_at or expires_at', function (string $driver): void {
    $store = pcpStore($driver, pcpGuards());

    // created 2026-08-01, consumed 2026-08-10, expires 2027 — cutoff 2026-08-05 sits BETWEEN created and
    // consumed, so a created_at (or expires_at) clock would prune it; a consumed_at clock keeps it.
    $kept = pcpSeedConsumed($store, 'call-kept', 'orders.cancel', hash('sha256', 'kept'), '2026-08-10 00:00:00');
    // consumed exactly at the cutoff must be pruned (inclusive).
    $boundary = pcpSeedConsumed($store, 'call-edge', 'orders.cancel', hash('sha256', 'edge'), '2026-08-05 00:00:00');

    /** @var PrunesConsumedApprovalPayload $store */
    expect($store->pruneConsumedPayload(pcpTime('2026-08-05 00:00:00')))->toBe(1)
        ->and($store->find($boundary->id))->toBeNull()
        ->and($store->find($kept->id))->not->toBeNull();
})->with('prune drivers');

it('is self-guarding: guarantees a MISSING guard before pruning a row a backfill missed', function (string $driver): void {
    $guards = pcpGuards();
    $store = pcpStore($driver, $guards);

    pcpSeedConsumed($store, 'call-x', 'orders.cancel', hash('sha256', 'x'), '2026-08-01 12:05:00');
    $guards->remembered = []; // simulate an old pre-guard consumer whose guard was never written

    /** @var PrunesConsumedApprovalPayload $store */
    expect($store->pruneConsumedPayload(pcpTime('2026-08-08 00:00:00')))->toBe(1)
        ->and($guards->has(pcpDigest('call-x', 'orders.cancel', hash('sha256', 'x'))))->toBeTrue();
})->with('prune drivers');

it('guarantees the guard BEFORE deleting the row — a failed guard write leaves the payload intact', function (string $driver): void {
    $guards = pcpGuards();
    $store = pcpStore($driver, $guards);

    $receipt = pcpSeedConsumed($store, 'call-atomic', 'orders.cancel', hash('sha256', 'atomic'), '2026-08-01 12:05:00');

    // Clear the guard so prune must (re-)write it — exercising the guarantee's failure atomicity on the
    // branch that actually inserts, and not rejecting an `if (! has()) remember()` implementation.
    $guards->remembered = [];
    expect($guards->has(pcpDigest('call-atomic', 'orders.cancel', hash('sha256', 'atomic'))))->toBeFalse();

    // From now on the guard guarantee fails; the prune must NOT delete the row (guard-write and delete
    // are one indivisible step, guard first).
    $guards->throwFromRemember = true;

    /** @var PrunesConsumedApprovalPayload $store */
    expect(fn () => $store->pruneConsumedPayload(pcpTime('2026-08-08 00:00:00')))->toThrow(RuntimeException::class);

    expect($store->find($receipt->id))->not->toBeNull()
        ->and($store->find($receipt->id)?->status)->toBe(ApprovalReceiptStatus::Consumed);
})->with('prune drivers');

it('refuses to prune consumed payload without a guard store, deleting nothing (same instance)', function (string $driver): void {
    // A guardless store can still consume (no guard written); pruning its consumed payload would strand
    // the binding, so it must fail closed and delete nothing.
    $store = pcpStore($driver, null);
    $receipt = pcpSeedConsumed($store, 'call-ng', 'orders.cancel', hash('sha256', 'ng'), '2026-08-01 12:05:00');
    expect($store->find($receipt->id))->not->toBeNull();

    /** @var PrunesConsumedApprovalPayload $store */
    expect(fn () => $store->pruneConsumedPayload(pcpTime('2026-08-08 00:00:00')))->toThrow(RuntimeException::class);

    expect($store->find($receipt->id))->not->toBeNull();
})->with('prune drivers');

it('acquires no guard and prunes nothing for a row that never admitted an execution', function (string $driver, string $state): void {
    $guards = pcpGuards();
    $store = pcpStore($driver, $guards);

    $receipt = pcpReceipt('call-'.$state, 'orders.cancel', hash('sha256', $state));
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    $expected = ApprovalReceiptStatus::Pending;
    if ($state === 'approved') {
        expect($store->approve($receipt->id, 'call-'.$state, 'human', pcpTime('2026-08-01 12:00:30'))->outcome)->toBe(ApprovalOutcome::Approved);
        $expected = ApprovalReceiptStatus::Approved;
    } elseif ($state === 'rejected') {
        expect($store->reject($receipt->id, 'call-'.$state, 'human', pcpTime('2026-08-01 12:00:30'))->outcome)->toBe(ApprovalOutcome::Rejected);
        $expected = ApprovalReceiptStatus::Rejected;
    }

    /** @var PrunesConsumedApprovalPayload $store */
    expect($store->pruneConsumedPayload(pcpTime('2027-06-01 00:00:00')))->toBe(0) // far-future cutoff
        ->and($store->find($receipt->id))->not->toBeNull()
        ->and($store->find($receipt->id)?->status)->toBe($expected)
        ->and($guards->remembered)->toBe([]); // pruning a non-consumed row must write no guard
})->with('prune drivers')->with(['pending', 'approved', 'rejected']);

it('leaves a pruned binding refused at issuance, minting nothing (guard governs issuance end to end)', function (string $driver): void {
    $guards = pcpGuards();
    $store = pcpStore($driver, $guards);

    $consumed = pcpSeedConsumed($store, 'call-e2e', 'orders.cancel', hash('sha256', 'e2e'), '2026-08-01 12:05:00');

    /** @var PrunesConsumedApprovalPayload $store */
    expect($store->pruneConsumedPayload(pcpTime('2026-08-08 00:00:00')))->toBe(1)
        ->and($store->find($consumed->id))->toBeNull(); // original payload gone

    $replay = pcpReceipt('call-e2e', 'orders.cancel', hash('sha256', 'e2e'));
    expect($store->issue($replay)->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed)
        ->and($store->find($replay->id))->toBeNull(); // the refused proposal was not persisted
})->with('prune drivers');

it('runs one admission-locked transaction per pruned row — each under its OWN pair lock, touching its OWN guard (pgsql)', function (string $scenario): void {
    $connection = app(DatabaseManager::class)->connection();
    $guards = pcpGuards();
    $store = new DatabaseApprovalReceiptStore(connection: $connection, guards: $guards);

    $r1 = pcpSeedConsumed($store, 'call-1', 'orders.cancel', hash('sha256', 'r1'), '2026-08-01 12:05:00');
    $r2 = pcpSeedConsumed($store, 'call-2', 'orders.refund', hash('sha256', 'r2'), '2026-08-01 12:06:00');
    $key1 = BindingAdmission::lockKey('call-1', hash('sha256', 'r1'));
    $key2 = BindingAdmission::lockKey('call-2', hash('sha256', 'r2'));
    $digest1 = pcpDigest('call-1', 'orders.cancel', hash('sha256', 'r1'));
    $digest2 = pcpDigest('call-2', 'orders.refund', hash('sha256', 'r2'));

    // both-missing: reusing one row's digest strands the other (its own guard is never written).
    // mixed: an implementation locking only when the guard is missing skips the existing-guard row.
    $guards->remembered = $scenario === 'mixed' ? [$digest2] : [];
    $r2Missing = $scenario !== 'mixed';
    expect($guards->has($digest1))->toBeFalse()
        ->and($guards->has($digest2))->toBe(! $r2Missing);

    // Ordered stream of queries, guard TOUCHES (has/remember, with digest+level) and tx boundaries.
    $timeline = [];
    $connection->beforeExecuting(function (string $query, array $bindings, $conn) use (&$timeline): void {
        $timeline[] = ['type' => 'query', 'sql' => $query, 'bindings' => $bindings, 'level' => $conn->transactionLevel()];
    });
    $guards->onRemember = function (string $digest) use ($connection, &$timeline): void {
        $timeline[] = ['type' => 'guarantee', 'digest' => $digest, 'level' => $connection->transactionLevel()];
    };
    $guards->onHas = function (string $digest) use ($connection, &$timeline): void {
        $timeline[] = ['type' => 'has', 'digest' => $digest, 'level' => $connection->transactionLevel()];
    };
    $only = fn (object $event): bool => $event->connection === $connection;
    app('events')->listen(TransactionBeginning::class, function ($e) use ($only, &$timeline): void {
        if ($only($e)) {
            $timeline[] = ['type' => 'begin', 'level' => $e->connection->transactionLevel()];
        }
    });
    app('events')->listen(TransactionCommitted::class, function ($e) use ($only, &$timeline): void {
        if ($only($e)) {
            $timeline[] = ['type' => 'commit', 'level' => $e->connection->transactionLevel()];
        }
    });
    app('events')->listen(TransactionRolledBack::class, function ($e) use ($only, &$timeline): void {
        if ($only($e)) {
            $timeline[] = ['type' => 'rollback', 'level' => $e->connection->transactionLevel()];
        }
    });

    expect($connection->transactionLevel())->toBe(0);

    /** @var PrunesConsumedApprovalPayload $store */
    expect($store->pruneConsumedPayload(pcpTime('2026-08-08 00:00:00')))->toBe(2)
        ->and($connection->transactionLevel())->toBe(0)
        ->and($guards->has($digest1))->toBeTrue()   // each binding's own guard is present afterward
        ->and($guards->has($digest2))->toBeTrue();

    expect(array_filter($timeline, fn (array $e): bool => $e['type'] === 'rollback'))->toBe([]);
    expect(array_values(array_filter($timeline, fn (array $e): bool => $e['type'] === 'commit' && $e['level'] === 0)))->toHaveCount(2);

    $deleteIndex = [];
    $firstTouch = fn (string $wantDigest): ?int => array_key_first(array_filter(
        $timeline,
        fn (array $e): bool => ($e['type'] === 'has' || $e['type'] === 'guarantee') && ($e['digest'] ?? null) === $wantDigest,
    ) ?: [null => null]);

    foreach ([[$key1, $r1->id, $digest1, true], [$key2, $r2->id, $digest2, $r2Missing]] as $index => [$key, $id, $digest, $wasMissing]) {
        // Indices (over the WHOLE timeline, so a touch before the row's lock — even in an outer/enclosing
        // transaction or a nested savepoint — is caught): this row's first pair lock, first own-guard
        // touch, and its delete.
        $advisoryAt = array_key_first(array_filter(
            $timeline,
            fn (array $e): bool => $e['type'] === 'query' && str_contains($e['sql'], 'pg_advisory_xact_lock') && in_array($key, $e['bindings']),
        ) ?: [null => null]);
        $ownTouchAt = $firstTouch($digest);
        $di = array_key_first(array_filter(
            $timeline,
            fn (array $e): bool => $e['type'] === 'query' && stripos($e['sql'], 'delete') !== false
                && str_contains($e['sql'], verdictTable('approvals')) && in_array($id, $e['bindings']),
        ) ?: [null => null]);

        expect($advisoryAt)->not->toBeNull('no pair lock for this row — even a row whose guard exists must be locked')
            ->and($ownTouchAt)->not->toBeNull("this row's OWN guard digest was never touched")
            ->and($di)->not->toBeNull('no delete bound to this row')
            ->and($timeline[$advisoryAt]['level'])->toBeGreaterThanOrEqual(1)
            ->and($timeline[$ownTouchAt]['level'])->toBeGreaterThanOrEqual(1)
            ->and($timeline[$di]['level'])->toBeGreaterThanOrEqual(1)
            // The lock precedes ANY access to this guard, and both precede the delete.
            ->and($advisoryAt)->toBeLessThan($ownTouchAt)
            ->and($ownTouchAt)->toBeLessThan($di);
        $deleteIndex[$index] = $di;

        // The lock is still held at the delete: no commit/rollback between the lock and the delete.
        for ($j = $advisoryAt + 1; $j < $di; $j++) {
            expect($timeline[$j]['type'])->not->toBe('commit');
            expect($timeline[$j]['type'])->not->toBe('rollback');
        }

        // A missing guard is WRITTEN (remember) after the lock and before the delete.
        if ($wasMissing) {
            $guaranteeAfterLock = array_filter(
                $timeline,
                fn (array $e, int $i): bool => $e['type'] === 'guarantee' && ($e['digest'] ?? null) === $digest && $i > $advisoryAt && $i < $di,
                ARRAY_FILTER_USE_BOTH,
            );
            expect($guaranteeAfterLock)->not->toBe([], 'a missing guard was not written under its lock before the delete');
        }
    }

    $orderedDeletes = $deleteIndex;
    sort($orderedDeletes);
    $topCommits = array_keys(array_filter($timeline, fn (array $e): bool => $e['type'] === 'commit' && $e['level'] === 0));
    expect(array_filter($topCommits, fn (int $c): bool => $c > $orderedDeletes[0] && $c < $orderedDeletes[1]))->not->toBe([]);
})->with(['both-missing', 'mixed'])->skip(fn (): bool => app(DatabaseManager::class)->connection()->getDriverName() !== 'pgsql', 'pgsql advisory lock + per-row transaction probe.');

it('durably persists the guarantee guard and the deletion together (real Database guard store)', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $guards = new DatabaseConsumedBindingGuardStore($connection, PCP_GUARD_TABLE);
    $store = new DatabaseApprovalReceiptStore(connection: $connection, guards: $guards);

    $receipt = pcpSeedConsumed($store, 'call-real', 'orders.cancel', hash('sha256', 'real'), '2026-08-01 12:05:00');

    // Remove the guard consume() wrote, so THIS test proves prune INSERTS the guarantee guard durably,
    // not merely that a pre-existing one survives.
    $connection->table(PCP_GUARD_TABLE)->delete();
    expect($guards->has(pcpDigest('call-real', 'orders.cancel', hash('sha256', 'real'))))->toBeFalse();

    /** @var PrunesConsumedApprovalPayload $store */
    expect($store->pruneConsumedPayload(pcpTime('2026-08-08 00:00:00')))->toBe(1)
        ->and($store->find($receipt->id))->toBeNull() // payload deleted...
        // ...and the guard prune inserted is durably present (read through a fresh store instance).
        ->and((new DatabaseConsumedBindingGuardStore($connection, PCP_GUARD_TABLE))
            ->has(pcpDigest('call-real', 'orders.cancel', hash('sha256', 'real'))))->toBeTrue();
});

it('guarantees the guard while the receipt still exists — remember() runs before the delete (Database)', function (): void {
    // Distinct from the failure-atomicity test: even when prune succeeds, the guard must be guaranteed
    // BEFORE the row is deleted. Observed from inside prune's transaction on the same connection, the
    // receipt row must still be present when remember() runs; a delete-then-remember impl (whose
    // rollback would otherwise hide the order) is caught here because the row is already gone.
    $connection = app(DatabaseManager::class)->connection();
    $guards = pcpGuards();
    $store = new DatabaseApprovalReceiptStore(connection: $connection, guards: $guards);

    $receipt = pcpSeedConsumed($store, 'call-order', 'orders.cancel', hash('sha256', 'order'), '2026-08-01 12:05:00');
    // Clear the guard THIS store reads (the recording double), so prune must (re)insert it and remember()
    // runs even for an `if (! has()) remember()` implementation.
    $guards->remembered = [];
    expect($guards->has(pcpDigest('call-order', 'orders.cancel', hash('sha256', 'order'))))->toBeFalse();

    $rowPresentAtGuarantee = null;
    $guards->onRemember = function () use ($connection, $receipt, &$rowPresentAtGuarantee): void {
        $rowPresentAtGuarantee = $connection->table(verdictTable('approvals'))->where('id', $receipt->id)->exists();
    };

    /** @var PrunesConsumedApprovalPayload $store */
    expect($store->pruneConsumedPayload(pcpTime('2026-08-08 00:00:00')))->toBe(1)
        ->and($rowPresentAtGuarantee)->toBeTrue('the guard was guaranteed only after the row was deleted')
        ->and($store->find($receipt->id))->toBeNull();
});
