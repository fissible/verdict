<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\DatabaseConsumedBindingGuardStore;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryConsumedBindingGuardStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardCollision;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\ServiceProvider;

// Slice 3 of ADR 0039: consume() persists the permanent binding guard INDIVISIBLY with the Consumed
// transition, keyed by the full triple (toolCallId, capability, bindingFingerprint). Nothing READS
// the guard yet (issue()'s guard-check + the admission lock are slice 4), so this is inert
// groundwork — but it must be exactly right: exactly one guard per successful consume, NONE on any
// non-consuming outcome, fail closed (never insert-or-ignore) on a pre-existing guard, and never
// half-apply the transition when the guard write fails.

const GUARD_TABLE = 'verdict_consumed_binding_guards';

/** A sentinel thrown only from a guard-store double, so a broad toThrow() cannot pass on an unrelated error. */
final class ConsumeGuardWriteFailed extends RuntimeException {}

function guardTestTime(string $at = '2026-08-01 12:00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

function guardTestReceipt(string $toolCallId, string $capability, string $fingerprint, string $expiresAt = '+1 hour'): ApprovalReceipt
{
    $now = guardTestTime();

    return new ApprovalReceipt(
        id: bin2hex(random_bytes(16)),
        toolCallId: $toolCallId,
        capability: $capability,
        bindingFingerprint: $fingerprint,
        provenance: null,
        approvalContext: null,
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm.',
        expiresAt: $now->modify($expiresAt),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $now,
        updatedAt: $now,
    );
}

/** Issue+approve, asserting each step so a mis-seeded state cannot report a clean guard result. */
function seedApprovedReceipt(ApprovalReceiptStore $store, string $toolCallId, string $capability, string $fingerprint, string $expiresAt = '+1 hour'): ApprovalReceipt
{
    $receipt = guardTestReceipt($toolCallId, $capability, $fingerprint, $expiresAt);

    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->approve($receipt->id, $toolCallId, 'approver', guardTestTime())->outcome)->toBe(ApprovalOutcome::Approved);

    return $receipt;
}

/** A guard store that records every remember() and answers has() from those records — for exact write assertions. */
function recordingGuardStore(): ConsumedBindingGuardStore
{
    return new class implements ConsumedBindingGuardStore
    {
        /** @var list<array{digest: string, at: DateTimeInterface}> */
        public array $remembered = [];

        public function has(string $digest): bool
        {
            return in_array($digest, array_column($this->remembered, 'digest'), true);
        }

        public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void
        {
            $this->remembered[] = ['digest' => $digest, 'at' => $consumedAt];
        }

        /** @return list<string> */
        public function digests(): array
        {
            return array_column($this->remembered, 'digest');
        }
    };
}

/** A guard store whose remember() always fails — to prove the transition is not half-applied. */
function throwingGuardStore(): ConsumedBindingGuardStore
{
    return new class implements ConsumedBindingGuardStore
    {
        public function has(string $digest): bool
        {
            return false;
        }

        public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void
        {
            throw new ConsumeGuardWriteFailed('guard write failed');
        }
    };
}

function receiptStoreWithGuards(string $driver, ConsumedBindingGuardStore $guards): ApprovalReceiptStore
{
    return $driver === 'database'
        ? new DatabaseApprovalReceiptStore(connection: app(DatabaseManager::class)->connection(), guards: $guards)
        : new InMemoryApprovalReceiptStore(guards: $guards);
}

dataset('store drivers', ['database', 'in-memory']);

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach ([verdictTable('approvals'), GUARD_TABLE, 'verdict_binding_admission_locks'] as $table) {
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

    foreach ([verdictTable('approvals'), GUARD_TABLE, 'verdict_binding_admission_locks'] as $table) {
        $schema->dropIfExists($table);
    }
});

it('persists the guard for the consumed binding (real stores, capability in the key)', function (string $driver): void {
    $guards = $driver === 'database'
        ? new DatabaseConsumedBindingGuardStore(app(DatabaseManager::class)->connection(), GUARD_TABLE)
        : new InMemoryConsumedBindingGuardStore;
    $store = receiptStoreWithGuards($driver, $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $capability = 'inventory.adjust';
    $fingerprint = hash('sha256', 'binding');
    seedApprovedReceipt($store, $toolCallId, $capability, $fingerprint);

    expect($store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00'))->outcome)
        ->toBe(ApprovalOutcome::Consumed);

    expect($guards->has(ConsumedBindingGuard::digest($toolCallId, $capability, $fingerprint)))->toBeTrue()
        ->and($guards->has(ConsumedBindingGuard::digest($toolCallId, 'orders.refund', $fingerprint)))->toBeFalse();
})->with('store drivers');

it('remembers exactly the full-triple digest once, using the receipt\'s real capability', function (string $driver): void {
    // A recording double: proves the EXACT digest written (so a hard-coded or omitted capability is
    // caught — the recorded digest would differ) and that remember() runs exactly once.
    $guards = recordingGuardStore();
    $store = receiptStoreWithGuards($driver, $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $capability = 'ledger.post'; // deliberately not the capability any other test uses
    $fingerprint = hash('sha256', 'exact');
    seedApprovedReceipt($store, $toolCallId, $capability, $fingerprint);

    $store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00'));

    expect($guards->remembered)->toHaveCount(1)
        ->and($guards->remembered[0]['digest'])->toBe(ConsumedBindingGuard::digest($toolCallId, $capability, $fingerprint))
        // the consumption INSTANT handed to the guard is the one passed to consume(), not createdAt/now.
        ->and($guards->remembered[0]['at']->format('Y-m-d H:i:s'))->toBe('2026-08-01 12:05:00');
})->with('store drivers');

it('writes exactly one guard row per successful consume (Database)', function (): void {
    $guards = new DatabaseConsumedBindingGuardStore(app(DatabaseManager::class)->connection(), GUARD_TABLE);
    $store = receiptStoreWithGuards('database', $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'single');
    seedApprovedReceipt($store, $toolCallId, 'orders.cancel', $fingerprint);

    expect($store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00'))->outcome)
        ->toBe(ApprovalOutcome::Consumed);

    expect(app(DatabaseManager::class)->connection()->table(GUARD_TABLE)->count())->toBe(1);
});

it('records NO guard write on any non-consuming outcome', function (string $driver, string $case): void {
    $guards = recordingGuardStore();
    $store = receiptStoreWithGuards($driver, $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', $case);

    $expected = match ($case) {
        'not-found' => ApprovalOutcome::NotFound,
        'pending' => (function () use ($store, $toolCallId, $fingerprint): ApprovalOutcome {
            expect($store->issue(guardTestReceipt($toolCallId, 'orders.cancel', $fingerprint))->outcome)->toBe(ApprovalOutcome::Issued);

            return ApprovalOutcome::InvalidState; // approved? no — still Pending
        })(),
        'rejected' => (function () use ($store, $toolCallId, $fingerprint): ApprovalOutcome {
            $r = guardTestReceipt($toolCallId, 'orders.cancel', $fingerprint);
            expect($store->issue($r)->outcome)->toBe(ApprovalOutcome::Issued);
            expect($store->reject($r->id, $toolCallId, 'rejector', guardTestTime())->outcome)->toBe(ApprovalOutcome::Rejected);

            return ApprovalOutcome::InvalidState;
        })(),
        'expired' => (function () use ($store, $toolCallId, $fingerprint): ApprovalOutcome {
            seedApprovedReceipt($store, $toolCallId, 'orders.cancel', $fingerprint, expiresAt: '+15 minutes');

            return ApprovalOutcome::Expired; // consume after expiry below
        })(),
        default => throw new RuntimeException("unknown case {$case}"),
    };

    $consumeAt = $case === 'expired' ? guardTestTime('2026-08-01 13:00:00') : guardTestTime('2026-08-01 12:05:00');

    expect($store->consume($toolCallId, $fingerprint, $consumeAt)->outcome)->toBe($expected)
        ->and($guards->remembered)->toBe([]);
})->with('store drivers')->with(['not-found', 'pending', 'rejected', 'expired']);

it('writes no additional guard when an already-consumed binding is consumed again', function (string $driver): void {
    $guards = recordingGuardStore();
    $store = receiptStoreWithGuards($driver, $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'twice');
    seedApprovedReceipt($store, $toolCallId, 'orders.cancel', $fingerprint);

    expect($store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00'))->outcome)->toBe(ApprovalOutcome::Consumed);
    expect($store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:06:00'))->outcome)->toBe(ApprovalOutcome::InvalidState);

    expect($guards->remembered)->toHaveCount(1); // the first consume only
})->with('store drivers');

it('does not half-apply the Consumed transition when the guard write fails', function (string $driver): void {
    // has() is false (no collision), remember() throws: the store must leave the receipt Approved,
    // consumed_at null — proving the guard write and the transition are indivisible.
    $store = receiptStoreWithGuards($driver, throwingGuardStore());

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'write-fail');
    $receipt = seedApprovedReceipt($store, $toolCallId, 'orders.cancel', $fingerprint);

    expect(fn () => $store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00')))
        ->toThrow(ConsumeGuardWriteFailed::class);

    $reloaded = $store->find($receipt->id);
    expect($reloaded?->status)->toBe(ApprovalReceiptStatus::Approved)
        ->and($reloaded?->consumedAt)->toBeNull();
})->with('store drivers');

it('fails closed (never insert-or-ignore) and does not consume when a guard already exists', function (string $driver): void {
    $guards = recordingGuardStore();
    $store = receiptStoreWithGuards($driver, $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $capability = 'orders.cancel';
    $fingerprint = hash('sha256', 'collision');
    $receipt = seedApprovedReceipt($store, $toolCallId, $capability, $fingerprint);

    $existing = ConsumedBindingGuard::digest($toolCallId, $capability, $fingerprint);
    $guards->remember($existing, guardTestTime('2026-07-01 00:00:00')); // a guard already present => has() true

    expect(fn () => $store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00')))
        ->toThrow(ConsumedBindingGuardCollision::class);

    // No second write, and the transition did not apply.
    expect($guards->digests())->toBe([$existing])
        ->and($store->find($receipt->id)?->status)->toBe(ApprovalReceiptStatus::Approved);
})->with('store drivers');

it('consumes normally without a guard store, writing no guard (backward compatible)', function (string $driver): void {
    $store = $driver === 'database'
        ? new DatabaseApprovalReceiptStore(connection: app(DatabaseManager::class)->connection())
        : new InMemoryApprovalReceiptStore;

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'no-guard');
    $receipt = seedApprovedReceipt($store, $toolCallId, 'orders.cancel', $fingerprint);

    expect($store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00'))->outcome)->toBe(ApprovalOutcome::Consumed)
        ->and($store->find($receipt->id)?->status)->toBe(ApprovalReceiptStatus::Consumed);

    if ($driver === 'database') {
        expect(app(DatabaseManager::class)->connection()->table(GUARD_TABLE)->count())->toBe(0);
    }
})->with('store drivers');

it('the container-resolved default Database store writes a guard on consume', function (): void {
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    app()->forgetInstance(ApprovalReceiptStore::class);

    $store = app(ApprovalReceiptStore::class);
    expect($store)->toBeInstanceOf(DatabaseApprovalReceiptStore::class);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $capability = 'orders.cancel';
    $fingerprint = hash('sha256', 'wired');
    seedApprovedReceipt($store, $toolCallId, $capability, $fingerprint);

    expect($store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00'))->outcome)->toBe(ApprovalOutcome::Consumed);

    // Read back through a binary-faithful guard store, not a string-bound where() on the BINARY digest.
    $guards = new DatabaseConsumedBindingGuardStore(app(DatabaseManager::class)->connection(), GUARD_TABLE);
    expect($guards->has(ConsumedBindingGuard::digest($toolCallId, $capability, $fingerprint)))->toBeTrue();
});

it('registers the consumed-binding-guard migration for real, fresh publication', function (): void {
    $paths = ServiceProvider::pathsToPublish(VerdictServiceProvider::class, 'verdict-migrations');

    $source = null;
    $destination = null;
    foreach ($paths as $from => $to) {
        if (basename($from) === 'create_verdict_consumed_binding_guards_table.php.stub') {
            $source = $from;
            $destination = $to;
        }
    }

    expect($source)->not->toBeNull('the guard migration is not registered for publishing')
        ->and(is_file((string) $source))->toBeTrue('the registered stub source does not exist on disk');

    // A real, dated migration destination under database/migrations.
    expect($destination)->toMatch('#[\\\\/]database[\\\\/]migrations[\\\\/]\d{4}_\d{2}_\d{2}_\d{6}_create_verdict_consumed_binding_guards_table\.php$#');

    // Sorts after a fixed released predecessor (chronological), not "newest forever" — two
    // concurrently-developed migrations cannot both be newest, and timestamp uniqueness is enforced
    // strictly by PublishedMigrationFilenamesTest.
    expect(basename((string) $destination, '.php'))
        ->toBeGreaterThan('2026_08_01_000000_create_verdict_approval_receipts_table');
});

it('rolls back a guard that WAS written when the consume transaction fails (Database shared transaction)', function (): void {
    // The decorator delegates a real insert to the guard store, then throws. If consume() wrote the
    // guard in the SAME transaction as the Consumed update, the rollback erases both; an
    // out-of-transaction guard write would survive and fail the "no persisted guard" assertion. This
    // proves shared-transaction atomicity without prescribing the write order.
    $connection = app(DatabaseManager::class)->connection();
    $real = new DatabaseConsumedBindingGuardStore($connection, GUARD_TABLE);

    $decorator = new class($real) implements ConsumedBindingGuardStore
    {
        public function __construct(private ConsumedBindingGuardStore $inner) {}

        public function has(string $digest): bool
        {
            return $this->inner->has($digest);
        }

        public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void
        {
            $this->inner->remember($digest, $consumedAt); // really inserts...

            if (! $this->inner->has($digest)) {
                throw new RuntimeException('decorator precondition: the guard was not actually written');
            }

            throw new ConsumeGuardWriteFailed('failure after a real guard write'); // ...then the transition step fails.
        }
    };

    $store = new DatabaseApprovalReceiptStore(connection: $connection, guards: $decorator);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $capability = 'orders.cancel';
    $fingerprint = hash('sha256', 'rollback');
    $receipt = seedApprovedReceipt($store, $toolCallId, $capability, $fingerprint);

    expect(fn () => $store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00')))
        ->toThrow(ConsumeGuardWriteFailed::class);

    $reloaded = $store->find($receipt->id);
    expect($reloaded?->status)->toBe(ApprovalReceiptStatus::Approved)
        ->and($reloaded?->consumedAt)->toBeNull()
        // the guard the decorator inserted must NOT survive — it shared the rolled-back transaction.
        ->and($real->has(ConsumedBindingGuard::digest($toolCallId, $capability, $fingerprint)))->toBeFalse()
        ->and($connection->table(GUARD_TABLE)->count())->toBe(0);
});

it('writes no guard and stays Approved when consume targets the wrong binding', function (string $driver, string $case): void {
    $guards = recordingGuardStore();
    $store = receiptStoreWithGuards($driver, $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'right-binding');
    $receipt = seedApprovedReceipt($store, $toolCallId, 'orders.cancel', $fingerprint);

    [$consumeTool, $consumeFingerprint] = $case === 'wrong-fingerprint'
        ? [$toolCallId, hash('sha256', 'other-binding')]
        : ['call-'.bin2hex(random_bytes(6)), $fingerprint];

    // Both shipped stores look up by (tool_call_id, binding_fingerprint), so a wrong component is NotFound.
    expect($store->consume($consumeTool, $consumeFingerprint, guardTestTime('2026-08-01 12:05:00'))->outcome)
        ->toBe(ApprovalOutcome::NotFound)
        ->and($guards->remembered)->toBe([])
        ->and($store->find($receipt->id)?->status)->toBe(ApprovalReceiptStatus::Approved);
})->with('store drivers')->with(['wrong-fingerprint', 'wrong-tool-call']);

it('stamps the guard with the consumption instant that consume() was given (Database)', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $guards = new DatabaseConsumedBindingGuardStore($connection, GUARD_TABLE);
    $store = receiptStoreWithGuards('database', $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'instant');
    $receipt = seedApprovedReceipt($store, $toolCallId, 'orders.cancel', $fingerprint);

    $store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00'));

    $storedConsumedAt = $connection->table(GUARD_TABLE)->value('consumed_at');
    expect((new DateTimeImmutable((string) $storedConsumedAt, new DateTimeZone('UTC')))->format('Y-m-d H:i:s'))
        ->toBe('2026-08-01 12:05:00')
        // and it agrees with the receipt's own recorded consumption instant.
        ->and($store->find($receipt->id)?->consumedAt?->format('Y-m-d H:i:s'))->toBe('2026-08-01 12:05:00');
});

it('leaves no guard behind when the receipt UPDATE itself fails (shared-transaction discriminator)', function (): void {
    // Complement to the guard-write-fails test: here the RECEIPT UPDATE fails (a trigger aborts the
    // Consumed write) while the guard store is real. If consume() committed the guard in its own
    // transaction before updating the receipt, that guard would survive this failure — so asserting
    // no guard survives forces guard-write and transition into ONE transaction, whichever runs first.
    $connection = app(DatabaseManager::class)->connection();
    $guards = new DatabaseConsumedBindingGuardStore($connection, GUARD_TABLE);
    $store = new DatabaseApprovalReceiptStore(connection: $connection, guards: $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $capability = 'orders.cancel';
    $fingerprint = hash('sha256', 'update-fail');
    $receipt = seedApprovedReceipt($store, $toolCallId, $capability, $fingerprint);

    // Abort any UPDATE that transitions the receipt to Consumed (after seeding, so approve() is fine).
    $consumedValue = ApprovalReceiptStatus::Consumed->value;
    $connection->statement(
        'CREATE TRIGGER block_consume BEFORE UPDATE ON '.verdictTable('approvals')
        ." FOR EACH ROW WHEN NEW.status = '{$consumedValue}'"
        ." BEGIN SELECT RAISE(ABORT, 'blocked'); END"
    );

    try {
        expect(fn () => $store->consume($toolCallId, $fingerprint, guardTestTime('2026-08-01 12:05:00')))
            ->toThrow(QueryException::class, 'blocked');

        expect($store->find($receipt->id)?->status)->toBe(ApprovalReceiptStatus::Approved)
            ->and($guards->has(ConsumedBindingGuard::digest($toolCallId, $capability, $fingerprint)))->toBeFalse()
            ->and($connection->table(GUARD_TABLE)->count())->toBe(0);
    } finally {
        $connection->statement('DROP TRIGGER IF EXISTS block_consume');
    }
})->skip(
    fn (): bool => app(DatabaseManager::class)->connection()->getDriverName() !== 'sqlite',
    'Uses a SQLite RAISE(ABORT) trigger to force the receipt UPDATE to fail; transaction semantics are driver-independent.',
);
