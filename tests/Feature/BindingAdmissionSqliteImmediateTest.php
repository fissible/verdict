<?php

declare(strict_types=1);

use Fissible\Verdict\Support\BindingAdmission;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * Slice 8 of ADR 0039: the SQLite arm of BindingAdmission::acquire(). On pgsql/mysql the coarse-pair
 * admission lock serializes concurrent security-state mutations; on SQLite acquire() was previously a
 * no-op, leaving issue()/consume()/prune() free to interleave their check-then-act. This proves the
 * SQLite arm now takes a real write lock at the first statement of the security-state transaction —
 * the portable equivalent of BEGIN IMMEDIATE that also works on PHP 8.3, where Laravel ignores the
 * transaction_mode connection option. Uses two genuinely independent connections to the SAME on-disk
 * database (a :memory: database is private per connection and cannot demonstrate cross-connection
 * locking), so every proof is DETERMINISTIC: a blocked acquire aborts immediately with SQLite's
 * "database is locked" rather than being inferred from timing.
 *
 * SQLite's write lock is database-wide, so its exclusion is deliberately COARSER than the per-key
 * advisory/row lock on the server engines: any concurrent security-state write is serialized, not
 * only the same (tool_call_id, binding_fingerprint) pair. That is intended for SQLite's single-writer
 * model and is pinned below.
 */
const IMM_TC = 'call-1';
const IMM_TC_OTHER = 'call-gamma';

function immFp(): string
{
    return str_repeat('a', 64);
}

function immFpOther(): string
{
    return str_repeat('c', 64);
}

/** Track the on-disk database files created this test so afterEach can remove them. */
function immTrackFiles(?string $add = null): array
{
    static $files = [];

    if ($add === '__reset__') {
        $files = [];

        return [];
    }

    if ($add !== null) {
        $files[] = $add;
    }

    return $files;
}

/**
 * A fresh, independent SQLite connection to $file (its own PDO handle, its own locks). A short
 * busy_timeout on the contender makes a blocked acquire abort DETERMINISTICALLY within the timeout —
 * the SQLite analog of the pgsql/mysql lock_timeout the server-engine suite sets — rather than
 * waiting on PDO_SQLite's long default while the holder keeps the lock for the whole assertion.
 */
function immConnection(string $name, string $file, ?int $busyTimeoutMs = null): ConnectionInterface
{
    $config = [
        'driver' => 'sqlite',
        'database' => $file,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ];

    if ($busyTimeoutMs !== null) {
        $config['busy_timeout'] = $busyTimeoutMs;
    }

    return app(DatabaseManager::class)->connectUsing($name, $config, force: true);
}

/**
 * A fresh on-disk SQLite database with the admission-lock table, and two independent connections to
 * it. Returns [holder, contender]; the contender carries a short busy_timeout.
 *
 * @return array{0: ConnectionInterface, 1: ConnectionInterface}
 */
function immPair(): array
{
    $file = tempnam(sys_get_temp_dir(), 'verdict_admission_sqlite_');
    immTrackFiles($file);

    verdictInstallBindingAdmissionLockTable(immConnection('imm_setup', $file)->getSchemaBuilder());

    return [immConnection('imm_holder', $file), immConnection('imm_contender', $file, busyTimeoutMs: 200)];
}

/** Whether a QueryException is SQLite's write-lock contention ("database is locked"). */
function immIsLocked(QueryException $exception): bool
{
    $message = strtolower($exception->getMessage());

    return str_contains($message, 'database is locked') || str_contains($message, 'database table is locked');
}

/** Assert acquire() on $contender BLOCKS (aborts with a database-locked error) while the holder holds it. */
function immExpectBlocked(ConnectionInterface $contender, string $toolCallId, string $fingerprint): QueryException
{
    $contender->beginTransaction();
    $threw = null;

    try {
        BindingAdmission::acquire($contender, $toolCallId, $fingerprint);
    } catch (QueryException $exception) {
        $threw = $exception;
    } finally {
        $contender->rollBack();
    }

    expect($threw)->not->toBeNull('acquire() must abort while another connection holds the SQLite write lock, but it returned');
    expect(immIsLocked($threw))->toBeTrue('expected a "database is locked" error, got: '.$threw->getMessage());

    return $threw;
}

/** Assert acquire() on $contender SUCCEEDS (the lock is free) and leaves the connection usable. */
function immExpectAcquires(ConnectionInterface $contender, string $toolCallId, string $fingerprint): void
{
    $contender->transaction(fn () => BindingAdmission::acquire($contender, $toolCallId, $fingerprint));

    expect(true)->toBeTrue(); // reached here without throwing => the lock was free
}

beforeEach(function (): void {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('pdo_sqlite is required for the SQLite admission-lock arm.');
    }
});

afterEach(function (): void {
    $manager = app(DatabaseManager::class);

    foreach (['imm_setup', 'imm_holder', 'imm_contender'] as $name) {
        $manager->purge($name);
    }

    foreach (immTrackFiles() as $file) {
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            @unlink($file.$suffix);
        }
    }

    immTrackFiles('__reset__');
});

it('serializes a second connection on SQLite while the first holds the admission lock', function (): void {
    [$holder, $contender] = immPair();

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, IMM_TC, immFp());

    immExpectBlocked($contender, IMM_TC, immFp());

    $holder->rollBack();
});

it('serializes even a DIFFERENT pair, because SQLite takes a database-wide write lock', function (): void {
    [$holder, $contender] = immPair();

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, IMM_TC, immFp());

    // On pgsql/mysql a different (tool_call_id, fingerprint) pair would NOT block; on SQLite the
    // RESERVED write lock is whole-database, so a concurrent security-state write of any pair is
    // serialized. This coarser exclusion is the intended SQLite contract.
    immExpectBlocked($contender, IMM_TC_OTHER, immFpOther());

    $holder->rollBack();
});

it('releases the lock when the holding transaction commits', function (): void {
    [$holder, $contender] = immPair();

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, IMM_TC, immFp());

    immExpectBlocked($contender, IMM_TC, immFp()); // blocked while held

    $holder->commit();

    immExpectAcquires($contender, IMM_TC, immFp()); // free once the holder commits
});

it('releases the lock when the holding transaction rolls back', function (): void {
    [$holder, $contender] = immPair();

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, IMM_TC, immFp());

    immExpectBlocked($contender, IMM_TC, immFp()); // blocked while held

    $holder->rollBack();

    immExpectAcquires($contender, IMM_TC, immFp()); // free once the holder rolls back
});

it('lets one transaction acquire the same pair twice without self-blocking, and still excludes others', function (): void {
    [$holder, $contender] = immPair();

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, IMM_TC, immFp());
    BindingAdmission::acquire($holder, IMM_TC, immFp()); // re-entrant within the same transaction: no self-BUSY

    immExpectBlocked($contender, IMM_TC, immFp());

    $holder->rollBack();
});

it('surfaces the contention as a concurrency error TransactionRetry retries', function (): void {
    [$holder, $contender] = immPair();

    $holder->beginTransaction();
    BindingAdmission::acquire($holder, IMM_TC, immFp());

    $exception = immExpectBlocked($contender, IMM_TC, immFp());

    // The decisive tie to serialization: TransactionRetry only re-runs a victim whose error the
    // detector classifies as concurrency. A lock contention SQLite reports must be that kind, or the
    // second security-state transaction would fatally throw instead of retrying and observing the
    // committed state.
    expect((new ConcurrencyErrorDetector)->causedByConcurrencyError($exception))->toBeTrue();

    $holder->rollBack();
});
