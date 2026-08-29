<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\Events\ApprovalProposalChangedUnderOpenReceipt;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;

/**
 * #311 item 1 — a changed-argument re-issue keys on a different binding_fingerprint, so it misses
 * the dedup lookup and mints a *second* receipt under the same (toolCallId, capability) while the
 * first is still open. That mint is fail-closed (the new proposal needs its own approval), but it
 * was signal-free: an adopter UI keying a decision on toolCallId alone could pair an approval with
 * the wrong proposal. These tests pin the emitted signal — ApprovalProposalChangedUnderOpenReceipt
 * — and, as controls, pin the cases that must stay silent so the signal cannot be cried wolf.
 *
 * Both stores are exercised: the event contract must hold identically across the production
 * database store and the in-memory test store.
 */
beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));
    $schema->create(verdictTable('approvals'), function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('tool_call_id');
        $table->string('capability');
        $table->char('binding_fingerprint', 64);
        $table->string('status', 24);
        $table->text('reason')->nullable();
        $table->timestamp('expires_at');
        $table->string('approved_by')->nullable();
        $table->timestamp('approved_at')->nullable();
        $table->string('rejected_by')->nullable();
        $table->timestamp('rejected_at')->nullable();
        $table->timestamp('consumed_at')->nullable();
        $table->text('provenance')->nullable();
        $table->text('approval_context')->nullable();
        $table->timestamps();
        $table->unique(['tool_call_id', 'capability', 'binding_fingerprint'], 'verdict_approval_receipts_binding_unique');
    });
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('approvals'));
});

/** Stores are resolved AFTER Event::fake() so both receive the faked dispatcher. */
dataset('signalStores', [
    'database' => [fn (): DatabaseApprovalReceiptStore => new DatabaseApprovalReceiptStore(
        app(DatabaseManager::class)->connection(),
        verdictTable('approvals'),
        app(Dispatcher::class),
    )],
    'in-memory' => [fn (): InMemoryApprovalReceiptStore => new InMemoryApprovalReceiptStore(
        app(Dispatcher::class),
    )],
]);

function signalReceipt(
    string $fingerprint,
    string $id,
    string $toolCallId = 'call-shared',
    string $capability = 'orders.cancel',
    ApprovalReceiptStatus $status = ApprovalReceiptStatus::Pending,
    ?DateTimeImmutable $createdAt = null,
    ?DateTimeImmutable $expiresAt = null,
): ApprovalReceipt {
    $createdAt ??= new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    return new ApprovalReceipt(
        id: $id,
        toolCallId: $toolCallId,
        capability: $capability,
        bindingFingerprint: $fingerprint,
        provenance: null,
        approvalContext: null,
        status: $status,
        reason: 'Confirm.',
        expiresAt: $expiresAt ?? $createdAt->modify('+15 minutes'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $createdAt,
        updatedAt: $createdAt,
    );
}

$fpA = str_repeat('a', 64);
$fpB = str_repeat('b', 64);
$idA = str_repeat('1', 64);
$idB = str_repeat('2', 64);

it('signals when a changed-argument proposal is minted under an open pending receipt', function (Closure $makeStore) use ($fpA, $fpB, $idA, $idB): void {
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    expect($store->issue(signalReceipt($fpA, $idA))->outcome)->toBe(ApprovalOutcome::Issued);
    Event::assertNotDispatched(ApprovalProposalChangedUnderOpenReceipt::class);

    // Same toolCallId + capability, different binding (arguments changed) while A is still pending.
    expect($store->issue(signalReceipt($fpB, $idB))->outcome)->toBe(ApprovalOutcome::Issued);

    Event::assertDispatched(
        ApprovalProposalChangedUnderOpenReceipt::class,
        fn (ApprovalProposalChangedUnderOpenReceipt $e): bool => $e->toolCallId === 'call-shared'
            && $e->capability === 'orders.cancel'
            && $e->openReceiptId === $idA
            && $e->openReceiptFingerprint === $fpA
            && $e->newReceiptId === $idB
            && $e->newReceiptFingerprint === $fpB,
    );
    // Exactly one signal — a noisy impl that emits before AND after the insert must fail here.
    Event::assertDispatchedTimes(ApprovalProposalChangedUnderOpenReceipt::class, 1);
})->with('signalStores');

it('signals when the open receipt under a changed proposal is already approved', function (Closure $makeStore) use ($fpA, $fpB, $idA, $idB): void {
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    $store->issue(signalReceipt($fpA, $idA, status: ApprovalReceiptStatus::Approved));
    $store->issue(signalReceipt($fpB, $idB));

    // Full payload, so a status-specific lookup mistake cannot hide behind an id-only check.
    Event::assertDispatched(
        ApprovalProposalChangedUnderOpenReceipt::class,
        fn (ApprovalProposalChangedUnderOpenReceipt $e): bool => $e->toolCallId === 'call-shared'
            && $e->capability === 'orders.cancel'
            && $e->openReceiptId === $idA
            && $e->openReceiptFingerprint === $fpA
            && $e->newReceiptId === $idB
            && $e->newReceiptFingerprint === $fpB,
    );
    Event::assertDispatchedTimes(ApprovalProposalChangedUnderOpenReceipt::class, 1);
})->with('signalStores');

it('stays silent on an idempotent re-issue of the identical proposal (the existingIssue path)', function (Closure $makeStore) use ($fpA, $idA): void {
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    expect($store->issue(signalReceipt($fpA, $idA))->outcome)->toBe(ApprovalOutcome::Issued);
    // Identical binding — the Existing path, not a fresh mint. This is also the silence proof for the
    // database store's UniqueConstraintViolation recovery branch, which returns via the same
    // existingIssue() path: the signal must be emitted only from the successful fresh-mint branch,
    // never from existingIssue, so proving existingIssue silent proves the catch silent too.
    expect($store->issue(signalReceipt($fpA, $idA))->outcome)->toBe(ApprovalOutcome::Existing);

    Event::assertNotDispatched(ApprovalProposalChangedUnderOpenReceipt::class);
})->with('signalStores');

it('stays silent when the prior receipt for the tool call is already rejected', function (Closure $makeStore) use ($fpA, $fpB, $idA, $idB): void {
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    // Rejected is terminal — not an open approval. An impl treating 'anything not expired' as open
    // would wrongly signal here.
    $store->issue(signalReceipt($fpA, $idA, status: ApprovalReceiptStatus::Rejected));
    expect($store->issue(signalReceipt($fpB, $idB))->outcome)->toBe(ApprovalOutcome::Issued);

    Event::assertNotDispatched(ApprovalProposalChangedUnderOpenReceipt::class);
})->with('signalStores');

it('stays silent when the prior receipt expires exactly at the new proposal instant', function (Closure $makeStore) use ($fpA, $fpB, $idA, $idB): void {
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    // Expiry is '>=' (ApprovalReceipt::isExpiredAt), so expiresAt === the new receipt's createdAt is
    // already expired. Pin the exact boundary, not just plainly-live vs plainly-expired.
    $created = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
    $store->issue(signalReceipt($fpA, $idA, createdAt: $created->modify('-15 minutes'), expiresAt: $created));
    expect($store->issue(signalReceipt($fpB, $idB, createdAt: $created))->outcome)->toBe(ApprovalOutcome::Issued);

    Event::assertNotDispatched(ApprovalProposalChangedUnderOpenReceipt::class);
})->with('signalStores');

it('stays silent when the prior receipt for the tool call is already consumed', function (Closure $makeStore) use ($fpA, $fpB, $idA, $idB): void {
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    // A closed (consumed) receipt is not an *open* approval, so a later differing proposal is a
    // fresh request, not a change under something awaiting decision.
    $store->issue(signalReceipt($fpA, $idA, status: ApprovalReceiptStatus::Consumed));
    expect($store->issue(signalReceipt($fpB, $idB))->outcome)->toBe(ApprovalOutcome::Issued);

    Event::assertNotDispatched(ApprovalProposalChangedUnderOpenReceipt::class);
})->with('signalStores');

it('stays silent when the prior open receipt has expired', function (Closure $makeStore) use ($fpA, $fpB, $idA, $idB): void {
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    $past = new DateTimeImmutable('2026-08-01 10:00:00', new DateTimeZone('UTC'));
    // A pending-but-expired receipt is closed for all practical purposes.
    $store->issue(signalReceipt($fpA, $idA, createdAt: $past, expiresAt: $past->modify('+1 minute')));
    expect($store->issue(signalReceipt($fpB, $idB))->outcome)->toBe(ApprovalOutcome::Issued);

    Event::assertNotDispatched(ApprovalProposalChangedUnderOpenReceipt::class);
})->with('signalStores');

it('stays silent when the differing proposal is for a different tool call', function (Closure $makeStore) use ($fpA, $fpB, $idA, $idB): void {
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    $store->issue(signalReceipt($fpA, $idA, toolCallId: 'call-one'));
    expect($store->issue(signalReceipt($fpB, $idB, toolCallId: 'call-two'))->outcome)->toBe(ApprovalOutcome::Issued);

    Event::assertNotDispatched(ApprovalProposalChangedUnderOpenReceipt::class);
})->with('signalStores');

it('stays silent when the differing proposal is for a different capability', function (Closure $makeStore) use ($fpA, $fpB, $idA, $idB): void {
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    // The signal is scoped to a (toolCall, capability) pair: a different capability under the same
    // tool call is a distinct action, not a changed proposal of the same one.
    $store->issue(signalReceipt($fpA, $idA, capability: 'orders.cancel'));
    expect($store->issue(signalReceipt($fpB, $idB, capability: 'orders.refund'))->outcome)->toBe(ApprovalOutcome::Issued);

    Event::assertNotDispatched(ApprovalProposalChangedUnderOpenReceipt::class);
})->with('signalStores');

it('stays silent when a fresh-mint insert hits a unique violation and recovers (database only)', function () use ($fpA, $fpB, $idA): void {
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = new DatabaseApprovalReceiptStore(
        app(DatabaseManager::class)->connection(),
        verdictTable('approvals'),
        app(Dispatcher::class),
    );

    // Deterministically reach catch (UniqueConstraintViolationException): reuse the primary-key id.
    // The second issue takes the fresh-mint branch (its fingerprint is new, so the dedup lookup
    // misses), then its insert collides on the PRIMARY KEY and routes through the recovery catch,
    // which returns NotFound. The signal must be emitted only from a *successful* fresh mint, so the
    // recovery path — where no receipt was actually minted — must stay silent, proving the event is
    // dispatched post-commit on an Issued outcome, never speculatively from inside the try.
    expect($store->issue(signalReceipt($fpA, $idA))->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->issue(signalReceipt($fpB, $idA))->outcome)->toBe(ApprovalOutcome::NotFound);

    Event::assertNotDispatched(ApprovalProposalChangedUnderOpenReceipt::class);
});

it('dispatches the signal only after the transaction commits (database)', function () use ($fpA, $fpB, $idA, $idB): void {
    // Event::fake cannot prove post-commit: its assertDispatched closure runs long after the
    // transaction closes. A live listener captures the transaction nesting level at the exact moment
    // of dispatch — 0 proves the event fired outside SecurityStateTransaction::run(), so a mint whose
    // commit later fails cannot have already signalled.
    $levelAtDispatch = null;
    Event::listen(
        ApprovalProposalChangedUnderOpenReceipt::class,
        function () use (&$levelAtDispatch): void {
            $levelAtDispatch = app(DatabaseManager::class)->connection()->transactionLevel();
        },
    );

    $store = new DatabaseApprovalReceiptStore(
        app(DatabaseManager::class)->connection(),
        verdictTable('approvals'),
        app(Dispatcher::class),
    );
    $store->issue(signalReceipt($fpA, $idA));
    $store->issue(signalReceipt($fpB, $idB));

    expect($levelAtDispatch)->toBe(0);
});

it('the container-resolved default store dispatches the signal through the framework dispatcher', function () use ($fpA, $fpB, $idA, $idB): void {
    // Guards the wiring: VerdictServiceProvider must construct the default DatabaseApprovalReceiptStore
    // WITH the framework dispatcher, or the signal is silently dead in production regardless of the
    // store's own logic.
    // The test suite defaults verdict.approvals.store to the in-memory store, so pin the database
    // store — the one the provider constructs without a dispatcher today — to exercise real wiring.
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    app()->forgetInstance(ApprovalReceiptStore::class);
    $store = app(ApprovalReceiptStore::class);

    expect($store)->toBeInstanceOf(DatabaseApprovalReceiptStore::class);

    $store->issue(signalReceipt($fpA, $idA));
    $store->issue(signalReceipt($fpB, $idB));

    Event::assertDispatched(
        ApprovalProposalChangedUnderOpenReceipt::class,
        fn (ApprovalProposalChangedUnderOpenReceipt $e): bool => $e->newReceiptId === $idB && $e->openReceiptId === $idA,
    );
});

it('references the most recent prior open receipt when several are open (deterministic selection)', function (Closure $makeStore) use ($fpA, $fpB): void {
    // The model may re-propose repeatedly, leaving several open receipts under one tool call. The
    // signal carries a single open receipt, so its choice must be deterministic: the most recently
    // created still-open receipt with a different binding.
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    $fpC = str_repeat('c', 64);
    $idA = str_repeat('1', 64);
    $idB = str_repeat('2', 64);
    $idC = str_repeat('3', 64);
    $t0 = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    $store->issue(signalReceipt($fpA, $idA, createdAt: $t0));
    $store->issue(signalReceipt($fpB, $idB, createdAt: $t0->modify('+1 minute')));
    $store->issue(signalReceipt($fpC, $idC, createdAt: $t0->modify('+2 minutes')));

    // C's signal must reference B (the most recent prior open), not A.
    Event::assertDispatched(
        ApprovalProposalChangedUnderOpenReceipt::class,
        fn (ApprovalProposalChangedUnderOpenReceipt $e): bool => $e->newReceiptId === $idC
            && $e->openReceiptId === $idB
            && $e->openReceiptFingerprint === $fpB,
    );
})->with('signalStores');

it('breaks equal-created_at ties by id when several prior receipts are open (deterministic selection)', function (Closure $makeStore) use ($fpA, $fpB): void {
    // When two prior open receipts share created_at (second-granularity timestamps make this real),
    // selection falls to a stable secondary key. Contract: (created_at DESC, id DESC) — the greatest
    // id among the newest-timestamp open receipts. Here A and B share t0, so id decides: B (id '2…')
    // over A (id '1…').
    Event::fake([ApprovalProposalChangedUnderOpenReceipt::class]);
    $store = $makeStore();

    $fpC = str_repeat('c', 64);
    $idA = str_repeat('1', 64);
    $idB = str_repeat('2', 64);
    $idC = str_repeat('3', 64);
    $t0 = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    $store->issue(signalReceipt($fpA, $idA, createdAt: $t0));
    $store->issue(signalReceipt($fpB, $idB, createdAt: $t0));
    $store->issue(signalReceipt($fpC, $idC, createdAt: $t0->modify('+1 minute')));

    Event::assertDispatched(
        ApprovalProposalChangedUnderOpenReceipt::class,
        fn (ApprovalProposalChangedUnderOpenReceipt $e): bool => $e->newReceiptId === $idC
            && $e->openReceiptId === $idB
            && $e->openReceiptFingerprint === $fpB,
    );
})->with('signalStores');
