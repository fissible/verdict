<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\IssuanceRefusalReason;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalRefusalEvidence;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Illuminate\Database\DatabaseManager;

// #527 follow-up: recordApprovalRefusal()'s UPDATE-then-INSERT upsert must not poison the caller's
// transaction under a concurrent insert. On PostgreSQL a failed statement aborts the whole enclosing
// transaction (SQLSTATE 25P02); the original try/catch retry ran INSIDE that aborted transaction, so
// it threw 25P02 and escaped — swallowed by ApprovalManager::refuse(), but leaving the caller's
// transaction dead at its next statement (and the attempt lost). The fix is an insert-or-ignore with
// no exception path (as DatabaseConsumedBindingGuardStore::remember() already does).
//
// The race is forced deterministically with two connections at REPEATABLE READ: the recorder's
// connection takes its snapshot BEFORE the row exists, a second connection commits the row (invisible
// to that snapshot), so the recorder's UPDATE matches 0 rows while its INSERT conflicts with the
// committed row — the exact UPDATE-miss / INSERT-conflict interleave, with no timing or blocking.

const RR_REFUSALS_TABLE = 'verdict_approval_refusals';

it('does not poison the caller transaction when a concurrent insert wins the refusal upsert race (pgsql)', function (): void {
    $manager = app(DatabaseManager::class);
    $primary = $manager->connection();

    (require __DIR__.'/../../database/migrations/create_verdict_approval_refusals_table.php.stub')->up();

    // A genuinely independent session on the same database, to commit the racing row.
    $racer = $manager->connectUsing('rr_racer', config('database.connections.'.$primary->getName()), force: true);

    $digest = str_repeat('a', 64);
    $evidence = new ApprovalRefusalEvidence(
        lane: ApprovalLane::Confirmation,
        reason: IssuanceRefusalReason::PreviouslyConsumed,
        capability: 'orders.cancel',
        bindingDigest: $digest,
        occurredAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        invocationId: null,
    );

    $recorder = new DatabaseEvidenceRecorder($primary);
    $poisoned = false;
    $escaped = null;

    try {
        // The caller owns the transaction (the normal Laravel pattern: an approval flow writing its
        // own state alongside Verdict's). Take the snapshot before the racing row exists.
        $primary->beginTransaction();
        $primary->statement('set transaction isolation level repeatable read');
        $primary->selectOne('select 1 as ready');

        // The racer commits the row for this digest; it is invisible to $primary's snapshot but present
        // in the unique index, so $primary's UPDATE misses and its INSERT conflicts.
        $racer->table(RR_REFUSALS_TABLE)->insert([
            'binding_digest' => $digest, 'lane' => 'confirmation', 'refusal_reason' => 'previously_consumed',
            'capability' => 'orders.cancel', 'attempt_count' => 1, 'invocation_id' => null,
            'first_seen_at' => '2026-09-01 12:00:00', 'last_seen_at' => '2026-09-01 12:00:00',
        ]);

        try {
            $recorder->recordApprovalRefusal($evidence);
        } catch (Throwable $e) {
            $escaped = $e; // the original code escapes with 25P02
        }

        // The caller's transaction must still be usable — the load-bearing assertion. On the buggy
        // code the transaction is aborted and this throws 25P02.
        try {
            $primary->selectOne('select count(*) as c from '.RR_REFUSALS_TABLE);
        } catch (Throwable) {
            $poisoned = true;
        }

        $primary->rollBack();
    } finally {
        $manager->purge('rr_racer');
        $primary->getSchemaBuilder()->dropIfExists(RR_REFUSALS_TABLE);
    }

    expect($escaped)->toBeNull('recordApprovalRefusal must not let a database error escape')
        ->and($poisoned)->toBeFalse('the caller transaction must not be left aborted by the upsert');
})->skip(fn (): bool => concurrencyTestDriver() !== 'pgsql', 'pgsql REPEATABLE READ snapshot race for the refusal upsert.');
