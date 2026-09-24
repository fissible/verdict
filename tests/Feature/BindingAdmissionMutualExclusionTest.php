<?php

declare(strict_types=1);

use Fissible\Verdict\Support\BindingAdmission;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * The coarse-pair binding-admission lock (ADR 0039) exists so a pruned receipt row — which can no
 * longer be lockForUpdate()'d — cannot be raced into a fresh issuance while a concurrent consume()
 * commits its guard. This proves the lock's contract against a REAL engine using two genuinely
 * independent connections (two server-side sessions) and a short lock_timeout on the contender, so
 * every proof is DETERMINISTIC — a blocked acquire aborts with the engine's lock-timeout error
 * within the timeout rather than being inferred from wall-clock overlap. A blocked acquire is
 * distinguished from an unrelated failure by its SQLSTATE (pgsql 55P03; mysql/mariadb HY000 + 1205),
 * so an ordinary error can never masquerade as successful exclusion. Self-skips on SQLite
 * (single-writer; its BEGIN IMMEDIATE arm and the consume()/issue() wiring are later slices).
 *
 * Fixed vectors of BOTH signs are used (not random) so the signed 64-bit key survives transport
 * through pg_advisory_xact_lock / the MySQL lock row on every run:
 *   lockKey('call-1',      a×64) =  2301213462760360818  (positive; matches the unit known-answer)
 *   lockKey('neg-probe-3', 'fp') = -2274087212235053863  (negative)
 */
const ADMISSION_TC = 'call-1';
const ADMISSION_TC_OTHER = 'call-gamma';
const ADMISSION_TC_NEG = 'neg-probe-3';
const ADMISSION_FP_NEG = 'fp';

function admissionFp(): string
{
    return str_repeat('a', 64);
}

function admissionFpOther(): string
{
    return str_repeat('c', 64);
}

/** A fresh, independent connection to the test database — never the (possibly test-wrapped) default. */
function admissionConnection(string $name): ConnectionInterface
{
    $manager = app(DatabaseManager::class);
    $config = config('database.connections.'.$manager->connection()->getName());

    return $manager->connectUsing($name, $config, force: true);
}

/** pgsql: SET lock_timeout (ms). mysql/mariadb: innodb_lock_wait_timeout (whole seconds, min 1). */
function admissionSetContenderTimeout(ConnectionInterface $connection): void
{
    match ($connection->getDriverName()) {
        'pgsql' => $connection->statement("SET lock_timeout = '400ms'"),
        'mysql', 'mariadb' => $connection->statement('SET SESSION innodb_lock_wait_timeout = 1'),
        default => null,
    };
}

/** Only the engine's lock-wait/timeout condition counts as blocking — never an unrelated QueryException. */
function admissionIsLockTimeout(string $driver, QueryException $exception): bool
{
    $sqlstate = $exception->errorInfo[0] ?? null;
    $native = $exception->errorInfo[1] ?? null;

    return match ($driver) {
        'pgsql' => $sqlstate === '55P03',
        'mysql', 'mariadb' => $sqlstate === 'HY000' && (int) $native === 1205,
        default => false,
    };
}

/** Assert acquire() on $connection for the pair BLOCKS (the holder holds it) and aborts with a lock timeout. */
function admissionExpectBlocked(ConnectionInterface $connection, string $toolCallId, string $fingerprint): void
{
    admissionSetContenderTimeout($connection);

    try {
        $connection->transaction(fn () => BindingAdmission::acquire($connection, $toolCallId, $fingerprint));
    } catch (QueryException $exception) {
        expect(admissionIsLockTimeout($connection->getDriverName(), $exception))->toBeTrue(
            'expected a lock-wait/timeout error, got SQLSTATE '.($exception->errorInfo[0] ?? '?').': '.$exception->getMessage(),
        );

        return;
    }

    expect(false)->toBeTrue('acquire() must block and time out on a pair another connection holds, but it returned');
}

/** Assert acquire() on $connection for the pair SUCCEEDS (not blocked); commits and leaves the connection usable. */
function admissionExpectAcquires(ConnectionInterface $connection, string $toolCallId, string $fingerprint): void
{
    admissionSetContenderTimeout($connection);

    $connection->transaction(fn () => BindingAdmission::acquire($connection, $toolCallId, $fingerprint));

    expect(true)->toBeTrue(); // reached here without throwing => the lock was free
}

beforeEach(function (): void {
    if (concurrencyTestDriver() === null) {
        $this->markTestSkipped(concurrencyTestSkipReason());
    }

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_binding_admission_locks');
    (require __DIR__.'/../../database/migrations/create_verdict_binding_admission_locks_table.php.stub')->up();
});

afterEach(function (): void {
    if (concurrencyTestDriver() === null) {
        return;
    }

    $manager = app(DatabaseManager::class);

    foreach (['admission_holder', 'admission_contender'] as $name) {
        $manager->purge($name);
    }

    $manager->connection()->getSchemaBuilder()->dropIfExists('verdict_binding_admission_locks');
});

it('blocks a second connection acquiring the same pair while the first holds it', function (): void {
    $holder = admissionConnection('admission_holder');
    $contender = admissionConnection('admission_contender');

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, ADMISSION_TC, admissionFp());

    admissionExpectBlocked($contender, ADMISSION_TC, admissionFp());

    $holder->rollBack();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('blocks a contender on an ALREADY-COMMITTED lock row (existing-row exclusion, not first-insert contention)', function (): void {
    $holder = admissionConnection('admission_holder');

    // Seed and COMMIT the lock row, so the holder below locks an EXISTING row rather than racing an
    // insert. A MySQL arm that only serializes concurrent inserts but does not FOR UPDATE an existing
    // row would let the contender through here.
    $holder->transaction(fn () => BindingAdmission::acquire($holder, ADMISSION_TC, admissionFp()));

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, ADMISSION_TC, admissionFp());

    admissionExpectBlocked(admissionConnection('admission_contender'), ADMISSION_TC, admissionFp());

    $holder->rollBack();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('blocks the same pair even when its key is negative (signed key survives transport)', function (): void {
    $holder = admissionConnection('admission_holder');
    $contender = admissionConnection('admission_contender');

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, ADMISSION_TC_NEG, ADMISSION_FP_NEG);

    admissionExpectBlocked($contender, ADMISSION_TC_NEG, ADMISSION_FP_NEG);

    $holder->rollBack();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('does not block a different pair, and requires BOTH components to match', function (): void {
    $holder = admissionConnection('admission_holder');
    $contender = admissionConnection('admission_contender');

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, ADMISSION_TC, admissionFp());

    // Differ in the fingerprint only, then the tool-call id only: either alone is a different pair,
    // so neither blocks. A lock keyed on just one component would block one of these.
    admissionExpectAcquires($contender, ADMISSION_TC, admissionFpOther());
    admissionExpectAcquires($contender, ADMISSION_TC_OTHER, admissionFp());

    $holder->rollBack();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('releases the lock when the holding transaction commits (transaction-scoped, not session-scoped)', function (): void {
    $holder = admissionConnection('admission_holder');
    $contender = admissionConnection('admission_contender');

    // Commit while the holder stays connected. A session-scoped lock would survive the commit and
    // the contender would time out; a transaction-scoped lock is released here.
    $holder->transaction(fn () => BindingAdmission::acquire($holder, ADMISSION_TC, admissionFp()));

    admissionExpectAcquires($contender, ADMISSION_TC, admissionFp());
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('releases the lock when the holding transaction rolls back', function (): void {
    $holder = admissionConnection('admission_holder');
    $contender = admissionConnection('admission_contender');

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, ADMISSION_TC, admissionFp());
    $holder->rollBack(); // holder stays connected

    admissionExpectAcquires($contender, ADMISSION_TC, admissionFp());
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('lets one open transaction acquire the same pair twice without deadlocking', function (): void {
    $connection = admissionConnection('admission_holder');

    $connection->beginTransaction();
    BindingAdmission::acquire($connection, ADMISSION_TC, admissionFp());
    BindingAdmission::acquire($connection, ADMISSION_TC, admissionFp()); // re-entrant within the same tx
    $connection->rollBack();

    expect(true)->toBeTrue();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('lets the same connection reacquire the pair in a later transaction (reuse of an existing lock row)', function (): void {
    $connection = admissionConnection('admission_holder');

    $connection->transaction(fn () => BindingAdmission::acquire($connection, ADMISSION_TC, admissionFp()));
    $connection->transaction(fn () => BindingAdmission::acquire($connection, ADMISSION_TC, admissionFp()));

    expect(true)->toBeTrue();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('acquires against the exact prescribed SIGNED lockKey (pgsql advisory-lock contract)', function (string $toolCallId, string $fingerprint): void {
    // Independently hold the advisory lock at the RAW SIGNED BindingAdmission::lockKey(pair), then
    // prove acquire(pair) contends with it. Parameterized over a positive and a negative key so an
    // implementation that abs()'d the key inside acquire() would lock the wrong (positive) key for
    // the negative case, fail to contend, and fail this test. pgsql-specific: the ADR names
    // pg_advisory_xact_lock(k).
    $holder = admissionConnection('admission_holder');
    $contender = admissionConnection('admission_contender');

    $holder->beginTransaction();
    $holder->select('SELECT pg_advisory_xact_lock(?)', [BindingAdmission::lockKey($toolCallId, $fingerprint)]);

    admissionExpectBlocked($contender, $toolCallId, $fingerprint);

    $holder->rollBack();
})->with([
    'positive key (+2301213462760360818)' => ['call-1', str_repeat('a', 64)],
    'negative key (-2274087212235053863)' => [ADMISSION_TC_NEG, ADMISSION_FP_NEG],
])->skip(fn (): bool => concurrencyTestDriver() !== 'pgsql', 'pgsql-specific advisory-lock key contract.');

it('drops the lock table on migration down()', function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $migration = require __DIR__.'/../../database/migrations/create_verdict_binding_admission_locks_table.php.stub';

    expect($schema->hasTable('verdict_binding_admission_locks'))->toBeTrue(); // created in beforeEach
    $migration->down();
    expect($schema->hasTable('verdict_binding_admission_locks'))->toBeFalse();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());
