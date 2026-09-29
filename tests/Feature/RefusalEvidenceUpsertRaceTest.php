<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\IssuanceRefusalReason;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalRefusalEvidence;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Illuminate\Database\DatabaseManager;

// #527 follow-up: recordApprovalRefusal()'s UPDATE-then-INSERT upsert must not poison the caller's
// transaction under a concurrent insert, at the PRODUCTION default isolation (READ COMMITTED). The
// original code did a plain INSERT and, on the unique violation, retried the UPDATE inside the
// try/catch — but on PostgreSQL a failed statement aborts the whole enclosing transaction, so the
// retry threw SQLSTATE 25P02 and escaped, leaving the caller's transaction dead (and the attempt
// lost). The fix is insert-or-ignore (ON CONFLICT DO NOTHING — no failed statement), as
// DatabaseConsumedBindingGuardStore::remember() already does.
//
// The race is forced deterministically WITHOUT snapshot isolation (which would introduce an unrelated
// 40001 the fix should not swallow): a child process holds an UNCOMMITTED insert for the digest, so
// the recorder's UPDATE misses (row invisible) and its INSERT blocks on the unique index; the child
// then commits, unblocking the INSERT onto a now-committed conflicting row — the exact READ COMMITTED
// interleave. MySQL/SQLite roll back only the statement, so the bug is pgsql-specific.

const RR_REFUSALS_TABLE = 'verdict_approval_refusals';

it('does not poison the caller transaction when a concurrent insert wins the refusal upsert race (pgsql)', function (): void {
    $manager = app(DatabaseManager::class);
    $primary = $manager->connection();
    $config = config('database.connections.'.$primary->getName());

    (require __DIR__.'/../../database/migrations/create_verdict_approval_refusals_table.php.stub')->up();

    $digest = str_repeat('a', 64);
    $child = tempnam(sys_get_temp_dir(), 'rr_child_').'.php';
    file_put_contents($child, <<<'PHP'
        <?php
        [$s, $dsn, $u, $p, $digest, $hold] = $argv;
        $pdo = new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        $pdo->prepare('insert into verdict_approval_refusals (binding_digest, lane, refusal_reason, capability, attempt_count, invocation_id, first_seen_at, last_seen_at) values (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$digest, 'confirmation', 'previously_consumed', 'orders.cancel', 1, null, '2026-09-01 12:00:00', '2026-09-01 12:00:00']);
        echo "inserted\n";
        flush();
        usleep(((int) $hold) * 1000);
        $pdo->commit();
        echo "committed\n";
        PHP);

    $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
    $escaped = null;
    $poisoned = false;
    $attempts = null;
    $proc = null;

    try {
        $proc = proc_open(
            [PHP_BINARY, $child, $dsn, (string) $config['username'], (string) $config['password'], $digest, '1200'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        // Wait until the child's UNCOMMITTED insert is in place (holding the unique-index lock).
        $line = '';
        while (($line = fgets($pipes[1])) !== false && trim($line) !== 'inserted') {
        }

        $recorder = new DatabaseEvidenceRecorder($primary);
        $evidence = new ApprovalRefusalEvidence(
            lane: ApprovalLane::Confirmation,
            reason: IssuanceRefusalReason::PreviouslyConsumed,
            capability: 'orders.cancel',
            bindingDigest: $digest,
            occurredAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
            invocationId: null,
        );

        // The caller owns the transaction (the normal pattern: an approval flow writing its own state
        // alongside Verdict's). The recorder's UPDATE misses (child's row uncommitted) and its INSERT
        // blocks on the unique index until the child commits, then conflicts with the committed row.
        $primary->beginTransaction();
        try {
            $recorder->recordApprovalRefusal($evidence);
        } catch (Throwable $e) {
            $escaped = $e; // the buggy code escapes with 25P02
        }

        // The caller's transaction must still be usable — the load-bearing assertion. On the buggy
        // code the transaction is aborted and this read throws.
        try {
            $attempts = (int) $primary->table(RR_REFUSALS_TABLE)->where('binding_digest', $digest)->value('attempt_count');
        } catch (Throwable) {
            $poisoned = true;
        }
        $primary->rollBack();
    } finally {
        if (is_resource($proc)) {
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            proc_close($proc);
        }
        @unlink($child);
        $primary->getSchemaBuilder()->dropIfExists(RR_REFUSALS_TABLE);
    }

    expect($escaped)->toBeNull('recordApprovalRefusal must not let a database error escape')
        ->and($poisoned)->toBeFalse('the caller transaction must not be left aborted by the upsert')
        ->and($attempts)->toBe(2); // child's insert (1) + the recorder's re-increment
})->skip(fn (): bool => concurrencyTestDriver() !== 'pgsql', 'pgsql READ COMMITTED blocking race for the refusal upsert.');
