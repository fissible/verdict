<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\IssuanceRefusalReason;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalRefusalEvidence;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Illuminate\Database\DatabaseManager;

// #536 (codex retro-review findings 3.1 + 3.3): robustness of recordApprovalRefusal()'s upsert.
//  3.1 A NON-conflict SQL error (an invocation_id over the varchar(255) column) inside a caller-owned
//      PostgreSQL transaction still aborts the whole transaction (25P02); #530 only fixed the ON
//      CONFLICT case. The write is best-effort, so it must isolate its failure behind a savepoint,
//      leaving the caller's transaction usable, and still SURFACE the error (rethrow) so
//      ApprovalManager's catch can dispatch EvidenceWriteFailed (manager-boundary observability is
//      already covered by RefusalEvidenceRecordingTest). Both the INSERT and UPDATE paths must be
//      protected, and the recorder must not prematurely commit the caller's transaction.
//  3.3 An out-of-order write must not move last_seen_at backward or overwrite newer metadata with
//      older values: first_seen_at = earliest, last_seen_at = latest, and latest-event metadata
//      advances only on a STRICTLY newer event. An equal-timestamp arrival still counts but
//      preserves the stored metadata.
//  3.2 (lost attempt if the row is pruned between the conflict-detect and the re-increment) is a pure
//      concurrency window with no deterministic, fix-agnostic test; it is a PENDING
//      implementation-review gate — the fix must be a single atomic INSERT … ON CONFLICT DO UPDATE
//      with no separate re-increment to race. Confirmed at implementation review, not here.

const RUR_TABLE = 'verdict_approval_refusals';

function rurDigest(string $seed): string
{
    return hash('sha256', $seed);
}

function rurEvidence(
    string $digest,
    string $occurredAt,
    ?string $invocationId,
    IssuanceRefusalReason $reason = IssuanceRefusalReason::PreviouslyConsumed,
    string $capability = 'orders.cancel',
): ApprovalRefusalEvidence {
    return new ApprovalRefusalEvidence(
        lane: ApprovalLane::Confirmation,
        reason: $reason,
        capability: $capability,
        bindingDigest: $digest,
        occurredAt: new DateTimeImmutable($occurredAt, new DateTimeZone('UTC')),
        invocationId: $invocationId,
    );
}

function rurRow(string $digest): ?stdClass
{
    return app(DatabaseManager::class)->connection()->table(RUR_TABLE)->where('binding_digest', $digest)->first();
}

function rurSeedValidRow(string $digest): void
{
    app(DatabaseManager::class)->connection()->table(RUR_TABLE)->insert([
        'binding_digest' => $digest, 'lane' => 'confirmation', 'refusal_reason' => 'previously_consumed',
        'capability' => 'orders.cancel', 'attempt_count' => 1, 'invocation_id' => null,
        'first_seen_at' => '2026-09-01 12:00:00', 'last_seen_at' => '2026-09-01 12:00:00',
    ]);
}

beforeEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(RUR_TABLE);
    (require __DIR__.'/../../database/migrations/create_verdict_approval_refusals_table.php.stub')->up();
});

afterEach(function (): void {
    $connection = app(DatabaseManager::class)->connection();
    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }
    $connection->getSchemaBuilder()->dropIfExists(RUR_TABLE);
});

/**
 * Run a failing refusal write (over-length invocation_id) inside a caller-owned transaction and
 * return [threw, levelAfter, poisoned]: the recorder must surface the error, keep the caller's
 * transaction level unchanged, and leave it usable (a subsequent read must not throw 25P02).
 *
 * @return array{0: ?Throwable, 1: int, 2: bool}
 */
function rurFailingWriteInsideCallerTx(DatabaseEvidenceRecorder $recorder, string $badDigest): array
{
    $connection = app(DatabaseManager::class)->connection();
    $threw = null;

    try {
        $recorder->recordApprovalRefusal(rurEvidence($badDigest, '2026-09-01 12:05:00', str_repeat('x', 300)));
    } catch (Throwable $e) {
        $threw = $e;
    }

    $levelAfter = $connection->transactionLevel();

    $poisoned = false;
    try {
        $connection->table(RUR_TABLE)->where('binding_digest', $badDigest)->exists();
    } catch (Throwable) {
        $poisoned = true;
    }

    return [$threw, $levelAfter, $poisoned];
}

it('does not poison a caller transaction on an INSERT-path non-conflict error, and still surfaces it (pgsql)', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    if ($connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Only PostgreSQL aborts the whole transaction on a failed statement (25P02).');
    }

    $recorder = new DatabaseEvidenceRecorder($connection);
    $callerDigest = rurDigest('caller-write-insert-path');
    $badDigest = rurDigest('insert-path-overlong'); // no existing row -> recorder takes the INSERT path

    $connection->beginTransaction();
    rurSeedValidRow($callerDigest); // the caller's own write, same transaction

    [$threw, $levelAfter, $poisoned] = rurFailingWriteInsideCallerTx($recorder, $badDigest);

    // Surfaced (rethrown so the manager can dispatch EvidenceWriteFailed), not silently dropped...
    expect($threw)->not->toBeNull()
        ->and($threw->getCode())->toBe('22001') // string_data_right_truncation, the intended non-conflict error
        // ...the caller still OWNS its transaction (a premature commit would drop the level to 0)...
        ->and($levelAfter)->toBe(1)
        // ...and the transaction is not poisoned.
        ->and($poisoned)->toBeFalse();

    $connection->commit();
    expect(rurRow($callerDigest))->not->toBeNull(); // the caller's own write survived the commit
});

it('does not poison a caller transaction on an UPDATE-path non-conflict error (pgsql)', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    if ($connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pgsql-only transaction-abort semantics.');
    }

    $recorder = new DatabaseEvidenceRecorder($connection);
    $badDigest = rurDigest('update-path-overlong');

    $connection->beginTransaction();
    rurSeedValidRow($badDigest); // row EXISTS -> recorder takes the UPDATE (increment) path, which also overflows

    [$threw, $levelAfter, $poisoned] = rurFailingWriteInsideCallerTx($recorder, $badDigest);

    expect($threw)->not->toBeNull()
        ->and($threw->getCode())->toBe('22001')
        ->and($levelAfter)->toBe(1)
        ->and($poisoned)->toBeFalse();

    // The seeded row (the caller's own write in this transaction) must SURVIVE the failed refusal
    // write, unchanged — its failed increment rolled back to the savepoint, not the whole caller
    // transaction. An implementation that rolled back the entire transaction and restarted a new one
    // (then rethrew 22001) would satisfy the level/poison/throw checks while losing this row.
    $afterFailure = rurRow($badDigest);
    expect($afterFailure)->not->toBeNull()
        ->and((int) $afterFailure->attempt_count)->toBe(1); // the failed increment did not apply

    // And because it was written inside the caller's transaction, the caller's own rollback discards it.
    $connection->rollBack();
    expect(rurRow($badDigest))->toBeNull();
});

it('does not prematurely commit the caller transaction: a caller rollback still discards the caller write (pgsql)', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    if ($connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pgsql-only transaction-abort semantics.');
    }

    $recorder = new DatabaseEvidenceRecorder($connection);
    $callerDigest = rurDigest('caller-write-rollback');
    $badDigest = rurDigest('rollback-variant-overlong');

    $connection->beginTransaction();
    rurSeedValidRow($callerDigest);

    rurFailingWriteInsideCallerTx($recorder, $badDigest);

    $connection->rollBack();

    // If the recorder had prematurely committed, the caller's write would survive the rollback.
    expect(rurRow($callerDigest))->toBeNull();
});

it('keeps first_seen_at earliest and last_seen_at latest, advancing metadata only on a strictly newer event', function (): void {
    $recorder = new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());
    $digest = rurDigest('out-of-order-three');

    // (1) a middle event, (2) an EARLIER arrival, (3) the NEWEST — each varies all latest-event metadata.
    $recorder->recordApprovalRefusal(rurEvidence($digest, '2026-09-01 12:10:00', 'inv-1', IssuanceRefusalReason::PreviouslyConsumed, 'cap.A'));
    $r = rurRow($digest);
    expect((int) $r->attempt_count)->toBe(1)
        ->and(substr((string) $r->first_seen_at, 0, 19))->toBe('2026-09-01 12:10:00')
        ->and(substr((string) $r->last_seen_at, 0, 19))->toBe('2026-09-01 12:10:00');

    $recorder->recordApprovalRefusal(rurEvidence($digest, '2026-09-01 12:00:00', 'inv-2', IssuanceRefusalReason::SummaryNotReleased, 'cap.B'));
    $r = rurRow($digest);
    expect((int) $r->attempt_count)->toBe(2)
        ->and(substr((string) $r->first_seen_at, 0, 19))->toBe('2026-09-01 12:00:00') // earliest now
        ->and(substr((string) $r->last_seen_at, 0, 19))->toBe('2026-09-01 12:10:00')  // NOT moved backward
        ->and($r->invocation_id)->toBe('inv-1')                                        // metadata unchanged (older event)
        ->and($r->refusal_reason)->toBe('previously_consumed')
        ->and($r->capability)->toBe('cap.A');

    // (3) the newest event: a wrong first_seen_at = min(last_seen_at, incoming) rule would lose 12:00 here.
    $recorder->recordApprovalRefusal(rurEvidence($digest, '2026-09-01 12:20:00', null, IssuanceRefusalReason::AttestNotConfigured, 'cap.C'));
    $r = rurRow($digest);
    expect((int) $r->attempt_count)->toBe(3)
        ->and(substr((string) $r->first_seen_at, 0, 19))->toBe('2026-09-01 12:00:00') // still the earliest
        ->and(substr((string) $r->last_seen_at, 0, 19))->toBe('2026-09-01 12:20:00')  // advanced
        ->and($r->invocation_id)->toBeNull()                                           // newest metadata, incl. null
        ->and($r->refusal_reason)->toBe('attest_not_configured')
        ->and($r->capability)->toBe('cap.C');
});

it('counts an equal-timestamp arrival but preserves the stored metadata (tie is not strictly newer)', function (): void {
    $recorder = new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());
    $digest = rurDigest('equal-timestamp-tie');

    $recorder->recordApprovalRefusal(rurEvidence($digest, '2026-09-01 12:00:00', 'inv-first', IssuanceRefusalReason::PreviouslyConsumed, 'cap.A'));
    $recorder->recordApprovalRefusal(rurEvidence($digest, '2026-09-01 12:00:00', 'inv-second', IssuanceRefusalReason::SummaryNotReleased, 'cap.B'));

    $r = rurRow($digest);
    expect((int) $r->attempt_count)->toBe(2)                                    // the tie still counts
        ->and(substr((string) $r->last_seen_at, 0, 19))->toBe('2026-09-01 12:00:00')
        ->and($r->invocation_id)->toBe('inv-first')                             // tie is not strictly newer: metadata preserved
        ->and($r->refusal_reason)->toBe('previously_consumed')
        ->and($r->capability)->toBe('cap.A');
});
