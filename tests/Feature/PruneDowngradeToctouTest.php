<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\ConsumedBindingGuardScheme;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\DerivedGuard;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardSchemeDowngraded;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

// #534 (codex retro-review finding 2.1): pruneConsumedPayload() runs its downgrade check
// (assertNotDowngraded -> ConsumedBindingGuardStore::hasSchemedGuard()) ONCE, outside the per-row
// transactions, and memoises it. Under Laravel read/write splitting that read can be served by a
// lagging replica: it returns false, the check passes, and the per-row loop then mints a KEYLESS
// guard for a binding that still has a keyed guard on the primary — reopening the offline-correlation
// exposure keyed mode exists to prevent. The fix must re-verify the downgrade condition FRESH, on the
// primary, INSIDE each row's transaction, and fail closed if a schemed guard is visible there.
//
// The replica-lag mechanism is modelled faithfully by tying hasSchemedGuard()'s answer to the
// connection's transaction state, NOT to a call count: OUTSIDE a transaction the read is the stale
// replica (false); INSIDE a transaction the read is the authoritative primary (the real truth). This
// both reproduces the bug (only the outside read runs today) and admits any correct fix, including one
// that removes the outer check entirely and performs its first authoritative check under each row's
// lock. Runs on every engine in the CI matrix; the lock-held probe (pdtLockHeld) is engine-aware, and
// fixtures use full-width binding fingerprints because the column is CHAR(64) (PostgreSQL space-pads).

const PDT_GUARD_TABLE = 'verdict_consumed_binding_guards';
const PDT_OLD_SECRET = 'old-keyed-secret-oooooooooooooooo'; // >= 32 chars: the retired keyed key

function pdtTime(string $at): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

/** A full-width (64-char) binding fingerprint: the column is CHAR(64), which PostgreSQL space-pads
 * a shorter value, so a short fixture would derive a different digest on pgsql than on sqlite/mysql. */
function pdtBinding(string $seed): string
{
    return hash('sha256', $seed);
}

/** Whether this row's binding admission lock is held, in an engine-appropriate way: BindingAdmission
 * writes a lock-table row on sqlite/mysql/mariadb but takes a pg_advisory_xact_lock (no row) on pgsql. */
function pdtLockHeld(ConnectionInterface $connection): bool
{
    if ($connection->getDriverName() === 'pgsql') {
        $row = $connection->selectOne('select count(*) as c from pg_locks where locktype = ? and pid = pg_backend_pid()', ['advisory']);

        return (int) ($row->c ?? 0) > 0;
    }

    return $connection->table('verdict_binding_admission_locks')->count() > 0;
}

/**
 * A guard-store double whose hasSchemedGuard() answers from the connection's transaction state:
 * false outside a transaction (the stale replica read), the authoritative truth inside one (the
 * primary). It records the transaction level at each call so a test can prove the authoritative
 * check happened in-transaction. Optionally the schemed guard only becomes visible AFTER the first
 * keyless guard is written, modelling a keyed guard surfacing mid-sweep.
 */
function pdtGuards(ConnectionInterface $connection, bool $keyedOrphan, bool $visibleAfterFirstRemember = false): ConsumedBindingGuardStore
{
    return new class($connection, $keyedOrphan, $visibleAfterFirstRemember) implements ConsumedBindingGuardStore
    {
        /** @var list<string> */
        public array $remembered = [];

        /** @var array<string, array{0: ?string, 1: ?string}> */
        public array $meta = [];

        /** @var list<int> transaction level captured at each hasSchemedGuard() call */
        public array $hasCallTxLevels = [];

        /** @var list<bool> whether a binding admission lock was already held at each hasSchemedGuard() call */
        public array $hasCallLockHeld = [];

        private bool $schemedVisible;

        public function __construct(
            private ConnectionInterface $connection,
            bool $keyedOrphan,
            private bool $visibleAfterFirstRemember,
        ) {
            $this->schemedVisible = $keyedOrphan && ! $visibleAfterFirstRemember;

            if ($keyedOrphan) {
                // A real keyed guard the retired keyed scheme wrote for the seeded binding.
                $digest = ConsumedBindingGuard::keyed('call-1', 'orders.cancel', pdtBinding('binding-1'), PDT_OLD_SECRET);
                $this->remembered[] = $digest;
                $this->meta[$digest] = [ConsumedBindingGuard::ALGORITHM_KEYED, 'v1'];
            }
        }

        public function lookup(string $digest): ?DerivedGuard
        {
            if (! in_array($digest, $this->remembered, true)) {
                return null;
            }

            [$algorithm, $keyVersion] = $this->meta[$digest] ?? [null, null];

            return new DerivedGuard($digest, $algorithm, $keyVersion);
        }

        public function hasSchemedGuard(): bool
        {
            $level = $this->connection->transactionLevel();
            $this->hasCallTxLevels[] = $level;

            // Record whether this row's binding admission lock is already held (engine-aware: a lock-table
            // row on sqlite/mysql, a pg_advisory_xact_lock on pgsql). BindingAdmission::acquire() takes it as
            // the first statement of the row transaction; its presence at check time proves the authoritative
            // check runs AFTER the lock, closing the "check before lock then wait for a concurrent keyed
            // prune to commit" race.
            $this->hasCallLockHeld[] = $level > 0 && pdtLockHeld($this->connection);

            // Outside a transaction the read is served by the lagging replica: stale, no guard seen.
            if ($level === 0) {
                return false;
            }

            // Inside a transaction the read is authoritative (primary).
            return $this->schemedVisible;
        }

        public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void
        {
            if (! in_array($digest, $this->remembered, true)) {
                $this->remembered[] = $digest;
            }

            $this->meta[$digest] = [$algorithm, $keyVersion];

            // A keyed guard surfaces on the primary once the first payload has been pruned.
            if ($this->visibleAfterFirstRemember) {
                $this->schemedVisible = true;
            }
        }

        /** @return list<string> the keyless digests written (algorithm null) */
        public function keylessWrites(): array
        {
            return array_values(array_filter(
                $this->remembered,
                fn (string $d): bool => ($this->meta[$d][0] ?? null) === null,
            ));
        }
    };
}

/** Insert a Consumed receipt row directly, bypassing consume() so no keyless guard is minted here. */
function pdtSeedConsumed(string $toolCallId, string $capability, string $fingerprint, string $consumedAt): void
{
    app(DatabaseManager::class)->connection()->table(verdictTable('approvals'))->insert([
        'id' => bin2hex(random_bytes(16)),
        'tool_call_id' => $toolCallId,
        'capability' => $capability,
        'binding_fingerprint' => $fingerprint,
        'provenance' => null,
        'status' => ApprovalReceiptStatus::Consumed->value,
        'reason' => 'Confirm.',
        'expires_at' => pdtTime('2027-01-01 00:00:00'),
        'approved_by' => 'human',
        'approved_at' => pdtTime('2026-08-01 12:00:30'),
        'rejected_by' => null,
        'rejected_at' => null,
        'consumed_at' => pdtTime($consumedAt),
        'created_at' => pdtTime('2026-08-01 12:00:00'),
        'updated_at' => pdtTime('2026-08-01 12:01:00'),
    ]);
}

function pdtStore(ConsumedBindingGuardStore $guards, ?ConsumedBindingGuardScheme $scheme): DatabaseApprovalReceiptStore
{
    return new DatabaseApprovalReceiptStore(
        connection: app(DatabaseManager::class)->connection(),
        guards: $guards,
        scheme: $scheme,
    );
}

/** A keyless-effective scheme that is present but retains no keys (distinct from a null scheme). */
function pdtEmptyKeyScheme(): ConsumedBindingGuardScheme
{
    return ConsumedBindingGuardScheme::fromConfig(['keys' => []]);
}

/** A keyed-effective scheme: exempt from the downgrade refusal because it can still probe keyed candidates. */
function pdtKeyedScheme(): ConsumedBindingGuardScheme
{
    return ConsumedBindingGuardScheme::fromConfig([
        'keys' => ['v1' => PDT_OLD_SECRET],
        'active_key' => 'v1',
    ]);
}

function pdtConsumedCount(): int
{
    return app(DatabaseManager::class)->connection()
        ->table(verdictTable('approvals'))
        ->where('status', ApprovalReceiptStatus::Consumed->value)
        ->count();
}

/** Whether the authoritative downgrade check ran inside a transaction AND after the binding lock was held. */
function pdtAuthoritativeCheckAfterLock(ConsumedBindingGuardStore $guards): bool
{
    /** @var list<bool> $locked */
    $locked = $guards->hasCallLockHeld; // @phpstan-ignore-line (anonymous double)

    return in_array(true, $locked, true);
}

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach ([verdictTable('approvals'), PDT_GUARD_TABLE, 'verdict_binding_admission_locks'] as $table) {
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

    foreach ([verdictTable('approvals'), PDT_GUARD_TABLE, 'verdict_binding_admission_locks'] as $table) {
        $schema->dropIfExists($table);
    }
});

it('fails closed when the authoritative (in-transaction) read reveals a keyed guard the stale outer read missed', function (?ConsumedBindingGuardScheme $scheme): void {
    $connection = app(DatabaseManager::class)->connection();
    $guards = pdtGuards($connection, keyedOrphan: true);
    pdtSeedConsumed('call-1', 'orders.cancel', pdtBinding('binding-1'), '2026-08-10 00:00:00');

    $before = [$guards->remembered, $guards->meta];
    $store = pdtStore($guards, $scheme);

    expect(fn () => $store->pruneConsumedPayload(pdtTime('2026-08-20 00:00:00')))
        ->toThrow(ConsumedBindingGuardSchemeDowngraded::class);

    // The authoritative check must have run inside a transaction AND after the binding admission lock
    // was acquired — a check before the lock still races a concurrent keyed prune that holds the lock
    // with an as-yet-uncommitted keyed guard.
    expect(pdtAuthoritativeCheckAfterLock($guards))->toBeTrue();

    // No payload pruned, and no NEW guard (keyless or otherwise) minted — state is exactly as seeded.
    expect(pdtConsumedCount())->toBe(1)
        ->and($guards->remembered)->toBe($before[0])
        ->and($guards->meta)->toBe($before[1]);
})->with([
    'null (keyless-default) scheme' => null,
    'present-but-empty-key scheme' => fn () => pdtEmptyKeyScheme(),
]);

it('still prunes a genuine keyless deployment with no schemed guard (does not over-fail-closed)', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $guards = pdtGuards($connection, keyedOrphan: false);
    pdtSeedConsumed('call-2', 'orders.cancel', pdtBinding('binding-2'), '2026-08-10 00:00:00');

    $pruned = pdtStore($guards, null)->pruneConsumedPayload(pdtTime('2026-08-20 00:00:00'));

    $keyless = ConsumedBindingGuard::digest('call-2', 'orders.cancel', pdtBinding('binding-2'));
    expect($pruned)->toBe(1)
        ->and(pdtConsumedCount())->toBe(0)
        ->and($guards->remembered)->toContain($keyless)
        ->and($guards->meta[$keyless])->toBe([null, null]); // minted keyless: null algorithm/version
});

it('still prunes under a keyed-effective scheme even with a keyed guard present (exempt from the downgrade refusal)', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $guards = pdtGuards($connection, keyedOrphan: true);
    pdtSeedConsumed('call-1', 'orders.cancel', pdtBinding('binding-1'), '2026-08-10 00:00:00');

    $pruned = pdtStore($guards, pdtKeyedScheme())->pruneConsumedPayload(pdtTime('2026-08-20 00:00:00'));

    // Keyed-effective: the active keyed guard is (re)written, the payload is pruned, no keyless mint.
    $keyed = ConsumedBindingGuard::keyed('call-1', 'orders.cancel', pdtBinding('binding-1'), PDT_OLD_SECRET);
    expect($pruned)->toBe(1)
        ->and(pdtConsumedCount())->toBe(0)
        ->and($guards->meta[$keyed])->toBe([ConsumedBindingGuard::ALGORITHM_KEYED, 'v1'])
        ->and($guards->keylessWrites())->toBe([]);
});

it('re-checks every row: a keyed guard surfacing mid-sweep stops the later rows without un-pruning the earlier one', function (): void {
    // Two eligible rows. The keyed guard becomes visible on the primary only AFTER the first row is
    // pruned. A fix that refreshes once per sweep (not per row) would prune both; the correct
    // per-row re-check prunes exactly one and fails closed on the other.
    $connection = app(DatabaseManager::class)->connection();
    $guards = pdtGuards($connection, keyedOrphan: false, visibleAfterFirstRemember: true);
    pdtSeedConsumed('call-1', 'orders.cancel', pdtBinding('binding-1'), '2026-08-10 00:00:00');
    pdtSeedConsumed('call-9', 'reports.export', pdtBinding('binding-9'), '2026-08-11 00:00:00');

    expect(fn () => $store = pdtStore($guards, null)->pruneConsumedPayload(pdtTime('2026-08-20 00:00:00')))
        ->toThrow(ConsumedBindingGuardSchemeDowngraded::class);

    // Exactly one row pruned (its own committed transaction survives), one preserved, and exactly
    // one keyless guard minted — the survivor got none.
    expect(pdtConsumedCount())->toBe(1)
        ->and($guards->keylessWrites())->toHaveCount(1);
});
