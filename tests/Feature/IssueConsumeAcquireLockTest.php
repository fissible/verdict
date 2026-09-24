<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Fissible\Verdict\Support\BindingAdmission;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\ServiceProvider;

// Slice 5 of ADR 0039: issue() and consume() acquire the coarse-pair binding-admission lock BEFORE
// their row work — one global lock order (admission first, then row ops) so there is no issue/consume
// inversion, closing the guard-check-then-insert race a later prune opens. Proven against a REAL
// engine: an independent holder holds the pair lock (BindingAdmission::acquire in an open tx); the
// contender's issue()/consume() then blocks and aborts with the engine's lock-timeout SQLSTATE. A
// beforeExecuting() recorder proves the operation attempted the admission lock FOR THE PAIR KEY and
// ran NO receipt-row query before it — i.e. acquisition genuinely precedes row work, not merely that
// a lock was taken somewhere. The lock is COARSE: a different capability on the same pair still
// blocks. Self-skips SQLite (acquire() is the slice-2 no-op there; its BEGIN IMMEDIATE arm + pruning
// are a later slice). Self-contained: only Pest.php globals.

const LOCK_TABLE = 'verdict_binding_admission_locks';

// A fixed, released historical predecessor every future migration must sort after (never "newest",
// which two concurrently-developed migrations cannot both be).
const LOCK_MIGRATION_PREDECESSOR = '2026_08_01_000000_create_verdict_approval_receipts_table';

function s5Time(string $at = '2026-08-01 12:00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

function s5Receipt(string $toolCallId, string $capability, string $fingerprint): ApprovalReceipt
{
    $now = s5Time();

    return new ApprovalReceipt(
        id: bin2hex(random_bytes(16)),
        toolCallId: $toolCallId,
        capability: $capability,
        bindingFingerprint: $fingerprint,
        provenance: null,
        approvalContext: null,
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm.',
        expiresAt: $now->modify('+1 hour'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $now,
        updatedAt: $now,
    );
}

function s5Conn(string $name): ConnectionInterface
{
    $manager = app(DatabaseManager::class);
    $config = config('database.connections.'.$manager->connection()->getName());

    return $manager->connectUsing($name, $config, force: true);
}

function s5Store(ConnectionInterface $connection): DatabaseApprovalReceiptStore
{
    return new DatabaseApprovalReceiptStore(connection: $connection); // guardless: isolates the lock
}

function s5SetContenderTimeout(ConnectionInterface $connection): void
{
    match ($connection->getDriverName()) {
        'pgsql' => $connection->statement("SET lock_timeout = '400ms'"),
        'mysql', 'mariadb' => $connection->statement('SET SESSION innodb_lock_wait_timeout = 1'),
        default => null,
    };
}

function s5IsLockTimeout(string $driver, QueryException $exception): bool
{
    $sqlstate = $exception->errorInfo[0] ?? null;
    $native = $exception->errorInfo[1] ?? null;

    return match ($driver) {
        'pgsql' => $sqlstate === '55P03',
        'mysql', 'mariadb' => $sqlstate === 'HY000' && (int) $native === 1205,
        default => false,
    };
}

/** True if a recorded query is the admission lock for exactly $lockKey (advisory call or lock-table op). */
function s5IsAdmissionQuery(string $sql, array $bindings, int $lockKey): bool
{
    $onThePairKey = in_array($lockKey, $bindings) || in_array((string) $lockKey, $bindings, true);

    return $onThePairKey && (str_contains($sql, 'pg_advisory_xact_lock') || str_contains($sql, LOCK_TABLE));
}

/**
 * Run $operation on $connection (an issue()/consume()) while a holder holds the pair lock, and prove:
 * it aborts with the engine's lock timeout; it attempted the admission lock for $lockKey; and NO
 * receipt-row query ran before that admission lock — acquisition precedes row work.
 */
function s5ExpectBlockedOnPair(ConnectionInterface $connection, int $lockKey, Closure $operation): void
{
    s5SetContenderTimeout($connection);

    /** @var list<array{sql: string, bindings: array}> $recorded */
    $recorded = [];
    $connection->beforeExecuting(function (string $query, array $bindings, $conn) use (&$recorded): void {
        $recorded[] = ['sql' => $query, 'bindings' => $bindings, 'txLevel' => $conn->transactionLevel()];
    });

    try {
        $operation();
    } catch (QueryException $exception) {
        expect(s5IsLockTimeout($connection->getDriverName(), $exception))->toBeTrue(
            'expected a lock-wait/timeout, got SQLSTATE '.($exception->errorInfo[0] ?? '?').': '.$exception->getMessage(),
        );

        $admissionSeen = false;
        $admissionInTransaction = false;
        $receiptQueryBeforeAdmission = false;
        foreach ($recorded as $q) {
            if (s5IsAdmissionQuery($q['sql'], $q['bindings'], $lockKey)) {
                $admissionSeen = true;
                $admissionInTransaction = $q['txLevel'] >= 1;
            } elseif (! $admissionSeen && str_contains($q['sql'], verdictTable('approvals'))) {
                $receiptQueryBeforeAdmission = true;
            }
        }

        // The query that actually blocked (the last attempted before the timeout) IS the admission
        // lock for the pair key — so the timeout is that lock, not some unrelated lock-timeout.
        $last = end($recorded);

        expect($admissionSeen)->toBeTrue('the operation never attempted the admission lock for the expected pair key')
            ->and($admissionInTransaction)->toBeTrue('the admission lock was taken outside a transaction (autocommit) — it would release before row work')
            ->and($receiptQueryBeforeAdmission)->toBeFalse('a receipt-row query ran before the admission lock — acquisition must precede row work')
            ->and($last !== false && s5IsAdmissionQuery($last['sql'], $last['bindings'], $lockKey))->toBeTrue('the timed-out query was not the admission lock for the expected pair key');

        return;
    }

    expect(false)->toBeTrue('the operation must block on the held coarse-pair lock and time out, but it returned');
}

beforeEach(function (): void {
    if (concurrencyTestDriver() === null) {
        return; // the publish test is driver-independent; the lock tests self-skip via ->skip()
    }

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));
    $schema->dropIfExists(LOCK_TABLE);

    foreach ([
        'create_verdict_approval_receipts_table.php.stub',
        'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        'add_approval_context_to_verdict_approval_receipts_table.php.stub',
        'create_verdict_binding_admission_locks_table.php.stub',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }
});

afterEach(function (): void {
    if (concurrencyTestDriver() === null) {
        return;
    }

    $manager = app(DatabaseManager::class);

    foreach (['s5_holder', 's5_contender', 's5_probe'] as $name) {
        $manager->purge($name);
    }

    $schema = $manager->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));
    $schema->dropIfExists(LOCK_TABLE);
});

it('issue() acquires the coarse-pair lock before any row work — blocking on a held pair, whatever the capability', function (string $capability): void {
    $holder = s5Conn('s5_holder');
    $contender = s5Conn('s5_contender');

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'binding');
    $lockKey = BindingAdmission::lockKey($toolCallId, $fingerprint);

    // Holder holds ONLY the pair lock — no receipt row, no capability — so nothing but a coarse-pair
    // admission lock can explain the contention.
    $holder->beginTransaction();
    BindingAdmission::acquire($holder, $toolCallId, $fingerprint);

    s5ExpectBlockedOnPair($contender, $lockKey, fn () => s5Store($contender)->issue(s5Receipt($toolCallId, $capability, $fingerprint)));

    $holder->rollBack();

    expect(app(DatabaseManager::class)->connection()->table(verdictTable('approvals'))->count())->toBe(0);
})->with(['orders.cancel', 'orders.refund'])->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('consume() acquires the coarse-pair lock before any row work — blocking with no receipt present', function (): void {
    $holder = s5Conn('s5_holder');
    $contender = s5Conn('s5_contender');

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'binding');
    $lockKey = BindingAdmission::lockKey($toolCallId, $fingerprint);

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, $toolCallId, $fingerprint);

    s5ExpectBlockedOnPair($contender, $lockKey, fn () => s5Store($contender)->consume($toolCallId, $fingerprint, s5Time('2026-08-01 12:05:00')));

    $holder->rollBack();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('consume() of an EXISTING approved receipt blocks on the pair lock, leaves it approved, then succeeds once released', function (string $capability): void {
    $connection = app(DatabaseManager::class)->connection();
    $seedStore = s5Store($connection);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'binding');
    $lockKey = BindingAdmission::lockKey($toolCallId, $fingerprint);

    // Seed a committed Approved receipt (no contention yet). approve() is not a binding-admission writer.
    $receipt = s5Receipt($toolCallId, $capability, $fingerprint);
    expect($seedStore->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($seedStore->approve($receipt->id, $toolCallId, 'human', s5Time())->outcome)->toBe(ApprovalOutcome::Approved);

    $holder = s5Conn('s5_holder');
    $contender = s5Conn('s5_contender');

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, $toolCallId, $fingerprint);

    s5ExpectBlockedOnPair($contender, $lockKey, fn () => s5Store($contender)->consume($toolCallId, $fingerprint, s5Time('2026-08-01 12:05:00')));

    // The blocked consume left the receipt approved (unconsumed).
    expect($seedStore->find($receipt->id)?->status)->toBe(ApprovalReceiptStatus::Approved);

    $holder->rollBack(); // release the pair lock

    expect(s5Store($connection)->consume($toolCallId, $fingerprint, s5Time('2026-08-01 12:06:00'))->outcome)
        ->toBe(ApprovalOutcome::Consumed);
})->with(['orders.cancel', 'orders.refund'])->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('does not block issue() when the held lock is for a different pair', function (): void {
    $holder = s5Conn('s5_holder');
    $contender = s5Conn('s5_contender');

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, 'call-'.bin2hex(random_bytes(6)), hash('sha256', 'other'));

    s5SetContenderTimeout($contender);
    $transition = s5Store($contender)->issue(s5Receipt('call-'.bin2hex(random_bytes(6)), 'orders.cancel', hash('sha256', 'mine')));

    expect($transition->outcome)->toBe(ApprovalOutcome::Issued);

    $holder->rollBack();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('registers the binding-admission-lock migration for real publication, after a fixed predecessor', function (): void {
    $paths = ServiceProvider::pathsToPublish(VerdictServiceProvider::class, 'verdict-migrations');

    $destination = null;
    foreach ($paths as $from => $to) {
        if (basename($from) === 'create_verdict_binding_admission_locks_table.php.stub') {
            expect(is_file((string) $from))->toBeTrue('the registered stub source does not exist on disk');
            $destination = $to;
        }
    }

    expect($destination)->not->toBeNull('the binding-admission-lock migration is not registered for publishing')
        ->and($destination)->toMatch('#[\\\\/]database[\\\\/]migrations[\\\\/]\d{4}_\d{2}_\d{2}_\d{6}_create_verdict_binding_admission_locks_table\.php$#')
        // Sorts after a fixed released predecessor (chronological), not "newest forever".
        ->and(basename((string) $destination, '.php'))->toBeGreaterThan(LOCK_MIGRATION_PREDECESSOR);
});

it('holds the coarse-pair lock throughout issue() row work — an independent session cannot take it (pgsql)', function (): void {
    // Rejects acquisition in autocommit OR in a separate transaction committed before row work: either
    // releases the advisory lock before issue() mints, so an independent session could take it here.
    $connection = app(DatabaseManager::class)->connection();
    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'held');
    $lockKey = BindingAdmission::lockKey($toolCallId, $fingerprint);

    $probe = new class($lockKey) implements ConsumedBindingGuardStore
    {
        public ?bool $independentSessionAcquired = null;

        public function __construct(private int $lockKey) {}

        public function has(string $digest): bool
        {
            // Runs INSIDE issue()'s transaction, after acquire() and before the insert. From a
            // genuinely independent session, try (non-blocking) to take the same pair advisory lock.
            $manager = app(DatabaseManager::class);
            $probeConn = $manager->connectUsing('s5_probe', config('database.connections.'.$manager->connection()->getName()), force: true);

            try {
                $probeConn->beginTransaction();
                $this->independentSessionAcquired = (bool) $probeConn->selectOne('SELECT pg_try_advisory_xact_lock(?) AS got', [$this->lockKey])->got;
                $probeConn->rollBack();
            } finally {
                $manager->purge('s5_probe');
            }

            return false; // guard absent -> issue proceeds to mint
        }

        public function remember(string $digest, DateTimeInterface $consumedAt): void {}
    };

    expect((new DatabaseApprovalReceiptStore(connection: $connection, guards: $probe))
        ->issue(s5Receipt($toolCallId, 'orders.cancel', $fingerprint))->outcome)->toBe(ApprovalOutcome::Issued);

    expect($probe->independentSessionAcquired)->toBeFalse('the pair lock was not held during issue() row work');
})->skip(fn (): bool => concurrencyTestDriver() !== 'pgsql', 'pgsql advisory held-during-row-work probe.');

it('holds the coarse-pair lock throughout consume() row work — an independent session cannot take it (pgsql)', function (): void {
    // Mirror of the issue() held-lock test for the consume() path: the probe runs in the guard store's
    // remember(), which consume() calls on the success path INSIDE its transaction. Rejects a
    // consume() that acquires in a separate transaction committed before its row work.
    $connection = app(DatabaseManager::class)->connection();
    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'held-consume');
    $lockKey = BindingAdmission::lockKey($toolCallId, $fingerprint);

    // Seed a committed Approved receipt with a guardless store (no contention).
    $seed = s5Store($connection);
    $receipt = s5Receipt($toolCallId, 'orders.cancel', $fingerprint);
    expect($seed->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($seed->approve($receipt->id, $toolCallId, 'human', s5Time())->outcome)->toBe(ApprovalOutcome::Approved);

    $probe = new class($lockKey) implements ConsumedBindingGuardStore
    {
        public ?bool $independentSessionAcquired = null;

        public function __construct(private int $lockKey) {}

        public function has(string $digest): bool
        {
            return false; // no collision
        }

        public function remember(string $digest, DateTimeInterface $consumedAt): void
        {
            // Runs INSIDE consume()'s transaction, during row work, while the lock must still be held.
            $manager = app(DatabaseManager::class);
            $probeConn = $manager->connectUsing('s5_probe', config('database.connections.'.$manager->connection()->getName()), force: true);

            try {
                $probeConn->beginTransaction();
                $this->independentSessionAcquired = (bool) $probeConn->selectOne('SELECT pg_try_advisory_xact_lock(?) AS got', [$this->lockKey])->got;
                $probeConn->rollBack();
            } finally {
                $manager->purge('s5_probe');
            }
        }
    };

    expect((new DatabaseApprovalReceiptStore(connection: $connection, guards: $probe))
        ->consume($toolCallId, $fingerprint, s5Time('2026-08-01 12:05:00'))->outcome)->toBe(ApprovalOutcome::Consumed);

    expect($probe->independentSessionAcquired)->toBeFalse('the pair lock was not held during consume() row work');
})->skip(fn (): bool => concurrencyTestDriver() !== 'pgsql', 'pgsql advisory held-during-row-work probe.');
