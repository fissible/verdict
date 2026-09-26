<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Fissible\Verdict\Contracts\PrunableApprovalReceiptStore;
use Fissible\Verdict\Contracts\PrunesConsumedApprovalPayload;
use Fissible\Verdict\Tests\Support\CustomStatusReaderTestStore;
use Fissible\Verdict\Tests\Support\FrozenClock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

// Slice 7 of ADR 0039: verdict:prune-approvals gains a --consumed-days option and a
// verdict.approvals.consumed_retention_days config key that prune CONSUMED receipt payloads via
// PrunesConsumedApprovalPayload::pruneConsumedPayload(). The consumed arm is independent of the
// existing expired arm (--days / retention_days -> pruneExpired, which deletes expired
// never-consumed receipts), opt-in, and clocked on consumed_at (inclusive) against the command's
// Clock. The command validates every requested window BEFORE deleting anything and runs the
// consumed arm BEFORE the expired arm, so an invalid input or a fail-closed refusal (a capable
// store with no guard store to preserve) deletes nothing at all. A store that does not implement
// the consumed marker is an informational no-op, mirroring the expired arm's contract.
// Self-contained: only Pest.php globals and Tests\Support doubles.

// A frozen "now" the command reads, leaving room to consume receipts in its past.
const PCA_NOW = '2026-08-20 12:00:00';

function pcaTime(string $at): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

function pcaReceipt(string $toolCallId, string $capability, string $fingerprint, string $expiresAt = '2027-01-01 00:00:00', string $createdAt = '2026-08-01 12:00:00'): ApprovalReceipt
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
        expiresAt: pcaTime($expiresAt),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: pcaTime($createdAt),
        updatedAt: pcaTime($createdAt),
    );
}

/**
 * Issue -> approve -> consume at $consumedAt through $store, asserting each step; returns the
 * receipt. $expiresAt/$approveAt let a test consume a receipt that expires before the command's
 * expired cutoff (to prove the expired arm excludes consumed rows by STATUS, not by date).
 */
function pcaSeedConsumed(ApprovalReceiptStore $store, string $toolCallId, string $capability, string $fingerprint, string $consumedAt, string $expiresAt = '2027-01-01 00:00:00', string $approveAt = '2026-08-01 12:00:30'): ApprovalReceipt
{
    $receipt = pcaReceipt($toolCallId, $capability, $fingerprint, expiresAt: $expiresAt);
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->approve($receipt->id, $toolCallId, 'human', pcaTime($approveAt))->outcome)->toBe(ApprovalOutcome::Approved);
    expect($store->consume($toolCallId, $fingerprint, pcaTime($consumedAt))->outcome)->toBe(ApprovalOutcome::Consumed);

    return $receipt;
}

/** Issue an already-expired, never-consumed receipt (fodder for the expired arm) through $store. */
function pcaSeedExpired(ApprovalReceiptStore $store, string $toolCallId, string $capability, string $fingerprint, string $expiresAt = '2020-01-01 00:00:00'): ApprovalReceipt
{
    $receipt = pcaReceipt($toolCallId, $capability, $fingerprint, expiresAt: $expiresAt, createdAt: '2019-12-01 00:00:00');
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);

    return $receipt;
}

/** A minimal in-memory guard store for the interface-discriminating store doubles. */
function pcaGuardStore(): ConsumedBindingGuardStore
{
    return new class implements ConsumedBindingGuardStore
    {
        /** @var list<string> */
        public array $remembered = [];

        public function has(string $digest): bool
        {
            return in_array($digest, $this->remembered, true);
        }

        public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void
        {
            if (! in_array($digest, $this->remembered, true)) {
                $this->remembered[] = $digest;
            }
        }
    };
}

/**
 * A store that implements PrunesConsumedApprovalPayload but NOT PrunableApprovalReceiptStore,
 * delegating to an inner InMemory store. Proves the consumed arm is gated on the consumed marker,
 * not on the (unrelated) expired marker.
 */
function pcaConsumedOnlyStore(): ApprovalReceiptStore
{
    $inner = new InMemoryApprovalReceiptStore(guards: pcaGuardStore());

    return new class($inner) implements ApprovalReceiptStore, PrunesConsumedApprovalPayload
    {
        public function __construct(private InMemoryApprovalReceiptStore $inner) {}

        public function issue(ApprovalReceipt $receipt): ApprovalTransition
        {
            return $this->inner->issue($receipt);
        }

        public function findForToolCall(string $toolCallId): ?ApprovalReceipt
        {
            return $this->inner->findForToolCall($toolCallId);
        }

        public function find(string $receiptId): ?ApprovalReceipt
        {
            return $this->inner->find($receiptId);
        }

        public function approve(string $receiptId, string $toolCallId, string $approvedBy, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->approve($receiptId, $toolCallId, $approvedBy, $at);
        }

        public function reject(string $receiptId, string $toolCallId, string $rejectedBy, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->reject($receiptId, $toolCallId, $rejectedBy, $at);
        }

        public function validate(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->validate($toolCallId, $bindingFingerprint, $at);
        }

        public function consume(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->consume($toolCallId, $bindingFingerprint, $at);
        }

        public function pruneConsumedPayload(DateTimeImmutable $consumedBefore): int
        {
            return $this->inner->pruneConsumedPayload($consumedBefore);
        }
    };
}

/**
 * A store that implements PrunableApprovalReceiptStore but NOT PrunesConsumedApprovalPayload,
 * delegating to an inner InMemory store. Proves the expired arm is gated on the expired marker.
 */
function pcaExpiredOnlyStore(): ApprovalReceiptStore
{
    $inner = new InMemoryApprovalReceiptStore(guards: pcaGuardStore());

    return new class($inner) implements ApprovalReceiptStore, PrunableApprovalReceiptStore
    {
        public function __construct(private InMemoryApprovalReceiptStore $inner) {}

        public function issue(ApprovalReceipt $receipt): ApprovalTransition
        {
            return $this->inner->issue($receipt);
        }

        public function findForToolCall(string $toolCallId): ?ApprovalReceipt
        {
            return $this->inner->findForToolCall($toolCallId);
        }

        public function find(string $receiptId): ?ApprovalReceipt
        {
            return $this->inner->find($receiptId);
        }

        public function approve(string $receiptId, string $toolCallId, string $approvedBy, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->approve($receiptId, $toolCallId, $approvedBy, $at);
        }

        public function reject(string $receiptId, string $toolCallId, string $rejectedBy, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->reject($receiptId, $toolCallId, $rejectedBy, $at);
        }

        public function validate(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->validate($toolCallId, $bindingFingerprint, $at);
        }

        public function consume(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->consume($toolCallId, $bindingFingerprint, $at);
        }

        public function pruneExpired(DateTimeImmutable $before): int
        {
            return $this->inner->pruneExpired($before);
        }
    };
}

/** The container store the command resolves, rebuilt after config changes, with the frozen clock bound. */
function pcaContainerStore(): ApprovalReceiptStore
{
    app()->instance(Clock::class, new FrozenClock(PCA_NOW));
    app()->forgetInstance(ApprovalReceiptStore::class);

    return app(ApprovalReceiptStore::class);
}

function pcaConnection(): ConnectionInterface
{
    return app(DatabaseManager::class)->connection();
}

beforeEach(function (): void {
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.approvals.connection', null);
    config()->set('verdict.approvals.retention_days', null);
    config()->set('verdict.approvals.consumed_retention_days', null);

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach ([verdictTable('approvals'), 'verdict_consumed_binding_guards', 'verdict_binding_admission_locks'] as $table) {
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

    foreach ([verdictTable('approvals'), 'verdict_consumed_binding_guards', 'verdict_binding_admission_locks'] as $table) {
        $schema->dropIfExists($table);
    }
});

it('ships the consumed retention config key defaulting to null', function (): void {
    // Read the shipped config file directly: beforeEach supplies the value in-test, so only the
    // file proves the published default an adopter inherits.
    $config = require __DIR__.'/../../config/verdict.php';

    expect($config['approvals'])->toHaveKey('consumed_retention_days')
        ->and($config['approvals']['consumed_retention_days'])->toBeNull();
});

it('prunes consumed payloads via --consumed-days, keeps the guard, and still refuses the replay', function (): void {
    $store = pcaContainerStore();

    $old = pcaSeedConsumed($store, 'call-old', 'orders.cancel', hash('sha256', 'old'), '2026-08-05 00:00:00'); // 15 days ago
    $recent = pcaSeedConsumed($store, 'call-recent', 'orders.refund', hash('sha256', 'recent'), '2026-08-19 12:00:00'); // 1 day ago

    // --consumed-days=7 => before = 2026-08-13 12:00:00: the 15-day-old row is past it, the 1-day-old is not.
    $this->artisan('verdict:prune-approvals', ['--consumed-days' => 7])
        ->expectsOutputToContain('Pruned 1 consumed')
        ->assertExitCode(0);

    expect($store->find($old->id))->toBeNull()
        ->and($store->find($recent->id))->not->toBeNull();

    // The decisive property: pruning deleted the payload but not the guard. Re-resolve the store so
    // the refusal is proven against persisted guard state, not the pruning instance's memory, and
    // assert the refused re-proposal is not itself persisted.
    $fresh = pcaContainerStore();
    $replay = pcaReceipt('call-old', 'orders.cancel', hash('sha256', 'old'));
    expect($fresh->issue($replay)->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed)
        ->and($fresh->find($replay->id))->toBeNull();
});

it('reads verdict.approvals.consumed_retention_days when --consumed-days is absent', function (): void {
    config()->set('verdict.approvals.consumed_retention_days', 7);
    $store = pcaContainerStore();

    $old = pcaSeedConsumed($store, 'call-old', 'orders.cancel', hash('sha256', 'old'), '2026-08-05 00:00:00'); // 15 days ago
    $recent = pcaSeedConsumed($store, 'call-recent', 'orders.refund', hash('sha256', 'recent'), '2026-08-19 12:00:00'); // 1 day ago

    $this->artisan('verdict:prune-approvals')
        ->expectsOutputToContain('Pruned 1 consumed')
        ->assertExitCode(0);

    // A window of 7 (not "prune everything") keeps the recent row.
    expect($store->find($old->id))->toBeNull()
        ->and($store->find($recent->id))->not->toBeNull();
});

it('lets an explicit --consumed-days override the configured window, including zero', function (): void {
    // A 30-day config window would keep a 15-day-old row; the explicit 7-day option sweeps it.
    config()->set('verdict.approvals.consumed_retention_days', 30);
    $store = pcaContainerStore();

    $old = pcaSeedConsumed($store, 'call-old', 'orders.cancel', hash('sha256', 'old'), '2026-08-05 00:00:00');

    $this->artisan('verdict:prune-approvals', ['--consumed-days' => 7])->assertExitCode(0);
    expect($store->find($old->id))->toBeNull();

    // Zero is a real window (cutoff = now, inclusive), and a falsy-fallback bug ($option ?: $config)
    // would let the config 30 win and keep rows consumed moments ago. Explicit '0' must override,
    // pruning a row consumed exactly at now while sparing one consumed a second later.
    $atNow = pcaSeedConsumed($store, 'call-now', 'orders.refund', hash('sha256', 'now'), PCA_NOW);
    $afterNow = pcaSeedConsumed($store, 'call-after-now', 'orders.pay', hash('sha256', 'after-now'), '2026-08-20 12:00:01');
    $this->artisan('verdict:prune-approvals', ['--consumed-days' => '0'])->assertExitCode(0);
    expect($store->find($atNow->id))->toBeNull()
        ->and($store->find($afterNow->id))->not->toBeNull();
});

it('prunes exactly at the cutoff instant and keeps a payload consumed one second later', function (): void {
    $store = pcaContainerStore();

    // --consumed-days=7 with now=2026-08-20 12:00:00 => cutoff = 2026-08-13 12:00:00 (inclusive).
    $onCutoff = pcaSeedConsumed($store, 'call-edge', 'orders.cancel', hash('sha256', 'edge'), '2026-08-13 12:00:00');
    $justAfter = pcaSeedConsumed($store, 'call-after', 'orders.refund', hash('sha256', 'after'), '2026-08-13 12:00:01');

    $this->artisan('verdict:prune-approvals', ['--consumed-days' => 7])
        ->expectsOutputToContain('Pruned 1 consumed')
        ->assertExitCode(0);

    expect($store->find($onCutoff->id))->toBeNull()
        ->and($store->find($justAfter->id))->not->toBeNull();
});

it('clocks consumed retention on consumed_at, not on a bumped updated_at', function (): void {
    $store = pcaContainerStore();

    $old = pcaSeedConsumed($store, 'call-old', 'orders.cancel', hash('sha256', 'old'), '2026-08-05 00:00:00');

    // Consumption sets updated_at = consumed_at; bump updated_at to "now" so a bug pruning against
    // updated_at would keep this row. Pruning against consumed_at (15 days old) must still delete it.
    pcaConnection()->table(verdictTable('approvals'))->where('id', $old->id)->update(['updated_at' => PCA_NOW]);

    $this->artisan('verdict:prune-approvals', ['--consumed-days' => 7])
        ->expectsOutputToContain('Pruned 1 consumed')
        ->assertExitCode(0);

    expect($store->find($old->id))->toBeNull();
});

it('leaves consumed payloads untouched when only --days is given, by status not by date', function (): void {
    $store = pcaContainerStore();

    $expired = pcaSeedExpired($store, 'call-exp', 'orders.cancel', hash('sha256', 'exp'));
    // Consumed before its own expiry, and that expiry is BEFORE the --days cutoff (2026-08-13): an
    // expired arm that matched by expires_at alone would wrongly delete it. Status-exclusion must save it.
    $consumed = pcaSeedConsumed($store, 'call-con', 'orders.refund', hash('sha256', 'con'), '2026-08-05 00:00:00', expiresAt: '2026-08-10 00:00:00');

    $this->artisan('verdict:prune-approvals', ['--days' => 7])
        ->expectsOutputToContain('Pruned 1 expired')
        ->assertExitCode(0);

    expect($store->find($expired->id))->toBeNull()
        ->and($store->find($consumed->id))->not->toBeNull()
        ->and($store->find($consumed->id)->status)->toBe(ApprovalReceiptStatus::Consumed);
});

it('leaves expired-unconsumed receipts untouched when only --consumed-days is given', function (): void {
    $store = pcaContainerStore();

    $expired = pcaSeedExpired($store, 'call-exp', 'orders.cancel', hash('sha256', 'exp'));
    $consumed = pcaSeedConsumed($store, 'call-con', 'orders.refund', hash('sha256', 'con'), '2026-08-05 00:00:00');

    $this->artisan('verdict:prune-approvals', ['--consumed-days' => 7])
        ->expectsOutputToContain('Pruned 1 consumed')
        ->assertExitCode(0);

    expect($store->find($consumed->id))->toBeNull()
        ->and($store->find($expired->id))->not->toBeNull();
});

it('prunes each arm against its own window with mixed option and config sources', function (): void {
    // Expired window 10 days (option) => cutoff 2026-08-10; consumed window 3 days (config) => cutoff 2026-08-17.
    // A survivor sits inside each window; a shared or swapped cutoff would move the wrong row.
    config()->set('verdict.approvals.consumed_retention_days', 3);
    $store = pcaContainerStore();

    $expiredOld = pcaSeedExpired($store, 'call-eo', 'orders.cancel', hash('sha256', 'eo'), expiresAt: '2026-08-05 00:00:00'); // < 08-10: pruned
    $expiredMid = pcaSeedExpired($store, 'call-em', 'orders.cancel', hash('sha256', 'em'), expiresAt: '2026-08-14 00:00:00'); // > 08-10: survives
    $consumedOld = pcaSeedConsumed($store, 'call-co', 'orders.refund', hash('sha256', 'co'), '2026-08-15 00:00:00'); // < 08-17: pruned
    $consumedRecent = pcaSeedConsumed($store, 'call-cr', 'orders.refund', hash('sha256', 'cr'), '2026-08-19 00:00:00'); // > 08-17: survives

    $this->artisan('verdict:prune-approvals', ['--days' => 10])
        ->expectsOutputToContain('Pruned 1 expired')
        ->expectsOutputToContain('Pruned 1 consumed')
        ->assertExitCode(0);

    expect($store->find($expiredOld->id))->toBeNull()
        ->and($store->find($expiredMid->id))->not->toBeNull()
        ->and($store->find($consumedOld->id))->toBeNull()
        ->and($store->find($consumedRecent->id))->not->toBeNull();
});

it('refuses and prunes nothing when neither retention window is chosen', function (): void {
    $store = pcaContainerStore();

    $consumed = pcaSeedConsumed($store, 'call-con', 'orders.refund', hash('sha256', 'con'), '2026-08-05 00:00:00');

    // Deleting security state on a schedule the operator never chose is worse than not pruning.
    $this->artisan('verdict:prune-approvals')->assertExitCode(1);

    expect($store->find($consumed->id))->not->toBeNull()
        ->and($store->find($consumed->id)->status)->toBe(ApprovalReceiptStatus::Consumed);
});

it('validates every window before deleting anything, so an invalid --consumed-days spares the expired arm', function (int|string $value): void {
    $store = pcaContainerStore();

    $expired = pcaSeedExpired($store, 'call-exp', 'orders.cancel', hash('sha256', 'exp'));
    $consumed = pcaSeedConsumed($store, 'call-con', 'orders.refund', hash('sha256', 'con'), '2026-08-05 00:00:00');

    // A valid expired window paired with an invalid consumed window must fail up front and delete
    // NOTHING — not even the (independently valid) expired rows.
    $this->artisan('verdict:prune-approvals', ['--days' => 7, '--consumed-days' => $value])->assertExitCode(1);

    expect($store->find($expired->id))->not->toBeNull()
        ->and($store->find($consumed->id))->not->toBeNull();
})->with([
    'negative' => -1,
    'non-numeric' => 'soon',
    'fractional' => '1.5',
    'scientific' => '1e3',
    'empty' => '',
]);

it('validates every window before deleting anything, so an invalid --days spares the consumed arm', function (int|string $value): void {
    $store = pcaContainerStore();

    $expired = pcaSeedExpired($store, 'call-exp', 'orders.cancel', hash('sha256', 'exp'));
    $consumed = pcaSeedConsumed($store, 'call-con', 'orders.refund', hash('sha256', 'con'), '2026-08-05 00:00:00');

    // The mirror case, and the load-bearing one: the consumed arm runs first, so a command that
    // pruned consumed rows before validating --days would delete the consumed row here. Every
    // requested window must be validated before any deletion.
    $this->artisan('verdict:prune-approvals', ['--consumed-days' => 7, '--days' => $value])->assertExitCode(1);

    expect($store->find($expired->id))->not->toBeNull()
        ->and($store->find($consumed->id))->not->toBeNull();
})->with([
    'negative' => -1,
    'non-numeric' => 'soon',
    'empty' => '',
]);

it('refuses an invalid window taken from config and prunes nothing', function (): void {
    // The window may come from config, not only the option; an invalid configured value is refused
    // up front, before any arm deletes a row. A VALID --days is supplied so the refusal cannot be
    // the trivial "neither window chosen" path: an implementation that silently treated the invalid
    // consumed config as absent would instead prune the expired row and succeed.
    config()->set('verdict.approvals.consumed_retention_days', -5);
    $store = pcaContainerStore();

    $expired = pcaSeedExpired($store, 'call-exp', 'orders.cancel', hash('sha256', 'exp'));
    $consumed = pcaSeedConsumed($store, 'call-con', 'orders.refund', hash('sha256', 'con'), '2026-08-05 00:00:00');

    $this->artisan('verdict:prune-approvals', ['--days' => 7])->assertExitCode(1);

    expect($store->find($expired->id))->not->toBeNull()
        ->and($store->find($consumed->id))->not->toBeNull()
        ->and($store->find($consumed->id)->status)->toBe(ApprovalReceiptStatus::Consumed);
});

it('fails closed and deletes nothing when a capable store cannot guarantee the guard', function (): void {
    // A store that IS a PrunesConsumedApprovalPayload but was built without a guard store: pruning
    // would strand the binding, so pruneConsumedPayload() throws and the command must surface a
    // failure rather than deleting the payload.
    app()->instance(Clock::class, new FrozenClock(PCA_NOW));
    $guardless = new DatabaseApprovalReceiptStore(connection: pcaConnection(), guards: null);
    app()->instance(ApprovalReceiptStore::class, $guardless);

    $consumed = pcaSeedConsumed($guardless, 'call-con', 'orders.refund', hash('sha256', 'con'), '2026-08-05 00:00:00');

    $this->artisan('verdict:prune-approvals', ['--consumed-days' => 7])
        ->expectsOutputToContain('guard')
        ->assertExitCode(1);

    expect($guardless->find($consumed->id))->not->toBeNull()
        ->and($guardless->find($consumed->id)->status)->toBe(ApprovalReceiptStatus::Consumed);
});

it('fails closed before the expired arm when both windows are given but the guard cannot be guaranteed', function (): void {
    // Consumed arm runs first; its fail-closed refusal must abort the whole command so the expired
    // rows are NOT deleted either — no partial, half-pruned outcome.
    app()->instance(Clock::class, new FrozenClock(PCA_NOW));
    $guardless = new DatabaseApprovalReceiptStore(connection: pcaConnection(), guards: null);
    app()->instance(ApprovalReceiptStore::class, $guardless);

    $expired = pcaSeedExpired($guardless, 'call-exp', 'orders.cancel', hash('sha256', 'exp'));
    $consumed = pcaSeedConsumed($guardless, 'call-con', 'orders.refund', hash('sha256', 'con'), '2026-08-05 00:00:00');

    $this->artisan('verdict:prune-approvals', ['--days' => 7, '--consumed-days' => 7])->assertExitCode(1);

    expect($guardless->find($expired->id))->not->toBeNull()
        ->and($guardless->find($consumed->id))->not->toBeNull();
});

it('says so and succeeds when the configured store cannot prune consumed payloads', function (): void {
    // A store that does not implement PrunesConsumedApprovalPayload has no consumed-payload
    // retention story; mirroring the expired arm, that is informational, not an error.
    config()->set('verdict.approvals.store', CustomStatusReaderTestStore::class);
    app()->instance(Clock::class, new FrozenClock(PCA_NOW));
    app()->forgetInstance(ApprovalReceiptStore::class);

    $this->artisan('verdict:prune-approvals', ['--consumed-days' => 7])
        ->expectsOutputToContain('cannot prune consumed payloads')
        ->assertExitCode(0);
});

it('prunes through a store that implements only the consumed marker', function (): void {
    // Discriminates the two markers: if the consumed arm were gated on PrunableApprovalReceiptStore,
    // this store (consumed marker only) could never prune.
    $store = pcaConsumedOnlyStore();
    app()->instance(Clock::class, new FrozenClock(PCA_NOW));
    app()->instance(ApprovalReceiptStore::class, $store);

    $old = pcaSeedConsumed($store, 'call-old', 'orders.cancel', hash('sha256', 'old'), '2026-08-05 00:00:00');

    $this->artisan('verdict:prune-approvals', ['--consumed-days' => 7])
        ->expectsOutputToContain('Pruned 1 consumed')
        ->assertExitCode(0);

    expect($store->find($old->id))->toBeNull();
});

it('prunes the consumed arm and skips the expired arm for a store that implements only the consumed marker', function (): void {
    // The reverse of the expired-only case: an unsupported EXPIRED arm must not make the command skip
    // the eligible consumed arm. Consumed pruning runs; the expired arm is an informational no-op.
    $store = pcaConsumedOnlyStore();
    app()->instance(Clock::class, new FrozenClock(PCA_NOW));
    app()->instance(ApprovalReceiptStore::class, $store);

    $old = pcaSeedConsumed($store, 'call-old', 'orders.cancel', hash('sha256', 'old'), '2026-08-05 00:00:00');

    $this->artisan('verdict:prune-approvals', ['--days' => 7, '--consumed-days' => 7])
        ->expectsOutputToContain('Pruned 1 consumed')
        ->expectsOutputToContain('does not require pruning')
        ->assertExitCode(0);

    expect($store->find($old->id))->toBeNull();
});

it('runs the expired arm and skips the consumed arm for a store that implements only the expired marker', function (): void {
    // The mirror discriminator: an expired-only store prunes via --days and reports the consumed arm
    // as an informational no-op via --consumed-days, in one run, succeeding overall.
    $store = pcaExpiredOnlyStore();
    app()->instance(Clock::class, new FrozenClock(PCA_NOW));
    app()->instance(ApprovalReceiptStore::class, $store);

    $expired = pcaSeedExpired($store, 'call-exp', 'orders.cancel', hash('sha256', 'exp'));

    $this->artisan('verdict:prune-approvals', ['--days' => 7, '--consumed-days' => 7])
        ->expectsOutputToContain('Pruned 1 expired')
        ->expectsOutputToContain('cannot prune consumed payloads')
        ->assertExitCode(0);

    expect($store->find($expired->id))->toBeNull();
});
