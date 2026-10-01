<?php

declare(strict_types=1);

use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

// #544 (split from #535): DatabaseReviewRequestStore::issueAdmitted() row-locks only an EXISTING
// binding (SELECT ... FOR UPDATE). For a MISSING binding nothing is locked, so two concurrent issuers
// with distinct ids and the same (capability, bindingFingerprint) both pass the admission read and
// both run the $onAdmitted attest hook before either inserts — the insert loser resolves to Existing
// but has already emitted a signed "issued" attestation for a request that never persisted. The fix:
// acquire the coarse BindingAdmission lock BEFORE the admission read, holding it through the hook and
// insert, so only the winner enters the critical section.
//
// Pinned the way #534/#540 pins it: the $onAdmitted hook must run with the binding admission lock
// already HELD (engine-aware — a lock-table row on sqlite/mysql/mariadb, a pg_advisory_xact_lock on
// pgsql). A hook that runs before any lock is acquired fails the probe.

function ralBinding(string $seed): string
{
    return hash('sha256', $seed); // full 64-char: binding_fingerprint is CHAR(64) (PostgreSQL space-pads short values)
}

/** Engine-aware: is a binding admission lock currently held? */
function ralLockHeld(ConnectionInterface $connection): bool
{
    if ($connection->getDriverName() === 'pgsql') {
        $row = $connection->selectOne('select count(*) as c from pg_locks where locktype = ? and pid = pg_backend_pid()', ['advisory']);

        return (int) ($row->c ?? 0) > 0;
    }

    return $connection->table('verdict_binding_admission_locks')->count() > 0;
}

function ralRequest(string $idSeed, string $bindingSeed): ReviewRequest
{
    return new ReviewRequest(
        id: hash('sha256', $idSeed),
        capability: 'orders.cancel',
        bindingFingerprint: ralBinding($bindingSeed),
        approvalContext: ['k' => 'v'],
        provenance: null,
        approverSummary: null,
        status: ReviewStatus::Pending,
        reason: 'Confirm.',
        createdAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        expiresAt: new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        resolvedBy: null,
        resolvedAt: null,
        consumedAt: null,
    );
}

function ralStore(): DatabaseReviewRequestStore
{
    return new DatabaseReviewRequestStore(app(DatabaseManager::class)->connection());
}

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_review_requests');
    $schema->dropIfExists('verdict_binding_admission_locks');
    (require __DIR__.'/../../database/migrations/create_verdict_review_requests_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_binding_admission_locks_table.php.stub')->up();
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    while (app(DatabaseManager::class)->connection()->transactionLevel() > 0) {
        app(DatabaseManager::class)->connection()->rollBack();
    }
    $schema->dropIfExists('verdict_review_requests');
    $schema->dropIfExists('verdict_binding_admission_locks');
});

it('holds the binding admission lock when the attest hook runs for a fresh (missing) binding', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $heldAtHook = null;
    $hookRan = false;

    $transition = ralStore()->issueAdmitted(
        ralRequest('review-1', 'binding-1'),
        function () use ($connection, &$heldAtHook, &$hookRan): void {
            $hookRan = true;
            $heldAtHook = ralLockHeld($connection);
        },
    );

    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($hookRan)->toBeTrue()
        // A fast, cross-engine smoke that SOME admission lock is held by the hook. It is NOT on its
        // own a proof of serialization (a lock acquired after the admission read would also be held
        // here); ReviewAdmissionConcurrencyTest is the authoritative mutual-exclusion proof.
        ->and($heldAtHook)->toBeTrue();
});

it('does not run the attest hook for an already-existing binding (the winner already holds it)', function (): void {
    // Positive control: a second issue of the same binding resolves to Existing WITHOUT attesting —
    // the fix must not start calling the hook on the existing-binding path.
    $store = ralStore();
    expect($store->issueAdmitted(ralRequest('review-1', 'binding-1'), fn () => null)->outcome)->toBe(ReviewOutcome::Issued);

    $hookRan = false;
    $transition = $store->issueAdmitted(
        ralRequest('review-2', 'binding-1'), // same binding, different id
        function () use (&$hookRan): void {
            $hookRan = true;
        },
    );

    expect($transition->outcome)->toBe(ReviewOutcome::Existing)
        ->and($hookRan)->toBeFalse();
});

it('still issues a plain (non-admitted) review request for a fresh binding', function (): void {
    // issue() is issueAdmitted() with a no-op hook; the admission lock must not break the happy path.
    expect(ralStore()->issue(ralRequest('review-3', 'binding-3'))->outcome)->toBe(ReviewOutcome::Issued);
});
