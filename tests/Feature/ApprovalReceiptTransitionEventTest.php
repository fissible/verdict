<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\Events\ApprovalProposalChangedUnderOpenReceipt;
use Fissible\Verdict\Approvals\Events\ApprovalReceiptTransitioned;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\SQLiteConnection;

/**
 * Issue #299. Receipt transitions mutated status in place and dispatched nothing, so a consumer
 * rendering pending approvals could only learn a receipt had resolved by polling — leaving a
 * resolved row actionable for up to one interval, long enough for two operators to act on the same
 * challenge.
 *
 * Two constraints shape what these tests allow. ADR 0008: an event crossing into a listener is a
 * context release, so the payload is identity plus status and nothing that the status read would
 * not already release — a listener that wants more asks the reader for it. And ADR 0029 §1 with
 * ADR 0031's rejected alternatives: expiry has no transition moment, no sweeper writes one, so
 * there are four transitions here and not the five the issue lists.
 */
function transitionEventSchema(Builder $schema): void
{
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
}

beforeEach(function (): void {
    transitionEventSchema(app(DatabaseManager::class)->connection()->getSchemaBuilder());
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('approvals'));
});

/**
 * Records what a listener actually received, and — the load-bearing part — whether the connection
 * still had an open transaction when it ran.
 */
final class RecordingTransitionListener
{
    /** @var list<ApprovalReceiptTransitioned> */
    public array $events = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public function __construct(private readonly DatabaseManager $db) {}

    public function handle(ApprovalReceiptTransitioned $event): void
    {
        $this->events[] = $event;
        $this->transactionLevels[] = $this->db->connection()->transactionLevel();
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return array_map(
            static fn (ApprovalReceiptTransitioned $event): string => $event->status->value,
            $this->events,
        );
    }
}

function transitionListener(): RecordingTransitionListener
{
    $listener = new RecordingTransitionListener(app(DatabaseManager::class));
    app(Dispatcher::class)->listen(
        ApprovalReceiptTransitioned::class,
        static fn (ApprovalReceiptTransitioned $event) => $listener->handle($event),
    );

    return $listener;
}

function transitionReceipt(
    string $id = 'receipt-transition',
    string $toolCallId = 'call-transition',
    string $capability = 'orders.cancel',
    ?DateTimeImmutable $createdAt = null,
    ?DateTimeImmutable $expiresAt = null,
    ?array $approvalContext = null,
): ApprovalReceipt {
    $now = $createdAt ?? new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    return new ApprovalReceipt(
        id: $id,
        toolCallId: $toolCallId,
        capability: $capability,
        bindingFingerprint: hash('sha256', $id),
        provenance: null,
        approvalContext: $approvalContext,
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm cancellation.',
        expiresAt: $expiresAt ?? $now->modify('+15 minutes'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $now,
        updatedAt: $now,
    );
}

function transitionAt(string $time = '12:05:00'): DateTimeImmutable
{
    return new DateTimeImmutable("2026-08-01 {$time}", new DateTimeZone('UTC'));
}

function transitionDatabaseStore(): ApprovalReceiptStore
{
    return new DatabaseApprovalReceiptStore(
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
    );
}

function transitionInMemoryStore(): ApprovalReceiptStore
{
    return new InMemoryApprovalReceiptStore(app(Dispatcher::class));
}

dataset('transition stores', [
    'database' => [fn (): ApprovalReceiptStore => transitionDatabaseStore()],
    'in-memory' => [fn (): ApprovalReceiptStore => transitionInMemoryStore()],
]);

// ---------------------------------------------------------------------------------------------
// The four transitions.
// ---------------------------------------------------------------------------------------------

it('dispatches on issuance, carrying identity and the resulting status', function (Closure $make): void {
    $listener = transitionListener();
    $store = $make();
    $receipt = transitionReceipt();

    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($listener->events)->toHaveCount(1);

    $event = $listener->events[0];

    expect($event->receiptId)->toBe('receipt-transition')
        ->and($event->toolCallId)->toBe('call-transition')
        ->and($event->capability)->toBe('orders.cancel')
        ->and($event->status)->toBe(ApprovalReceiptStatus::Pending)
        ->and($event->occurredAt->format(DATE_ATOM))->toBe($receipt->createdAt->format(DATE_ATOM));
})->with('transition stores');

it('dispatches on approval, rejection and consumption with the new status each time', function (Closure $make): void {
    $listener = transitionListener();
    $store = $make();
    $approved = transitionReceipt('receipt-approved', toolCallId: 'call-approved');
    $rejected = transitionReceipt('receipt-rejected', toolCallId: 'call-rejected');
    $store->issue($approved);
    $store->issue($rejected);

    // Three distinct instants, so an implementation that stamps its own clock instead of the
    // supplied one cannot pass.
    $store->approve($approved->id, $approved->toolCallId, 'user:42', transitionAt('12:05:00'));
    $store->reject($rejected->id, $rejected->toolCallId, 'user:42', transitionAt('12:06:00'));
    $store->consume($approved->toolCallId, $approved->bindingFingerprint, transitionAt('12:07:00'));

    // Two issuances then the three decisions, in order, each naming its resulting status.
    expect($listener->statuses())->toBe(['pending', 'pending', 'approved', 'rejected', 'consumed'])
        ->and($listener->events[2]->receiptId)->toBe('receipt-approved')
        ->and($listener->events[3]->receiptId)->toBe('receipt-rejected')
        ->and($listener->events[4]->receiptId)->toBe('receipt-approved')
        ->and($listener->events[4]->toolCallId)->toBe('call-approved')
        // Each event reports the instant its own transition was made at, not a clock read at
        // dispatch time — a consumer ordering a timeline depends on it.
        ->and($listener->events[2]->occurredAt->format(DATE_ATOM))->toBe('2026-08-01T12:05:00+00:00')
        ->and($listener->events[3]->occurredAt->format(DATE_ATOM))->toBe('2026-08-01T12:06:00+00:00')
        ->and($listener->events[4]->occurredAt->format(DATE_ATOM))->toBe('2026-08-01T12:07:00+00:00');
})->with('transition stores');

it('lets a consumer invalidate a row resolved by someone else without polling', function (Closure $make): void {
    // The symptom the issue names: a receipt decided elsewhere leaves a stale actionable row. The
    // event has to carry enough on its own to retire that row — the receipt it identifies and the
    // status it now holds — with no read back to the store.
    $listener = transitionListener();
    $store = $make();
    $receipt = transitionReceipt();
    $store->issue($receipt);

    $actionable = [$receipt->id => true];
    $store->approve($receipt->id, $receipt->toolCallId, 'operator:other', transitionAt());

    // The event is an invalidation hint, not an authority grant: what a consumer may do with it is
    // retire the row, not treat its own copy of the status as authoritative. Anything the consumer
    // then needs is a read through the status contract.
    foreach ($listener->events as $event) {
        if ($event->status !== ApprovalReceiptStatus::Pending) {
            unset($actionable[$event->receiptId]);
        }
    }

    expect($actionable)->toBe([]);
})->with('transition stores');

// ---------------------------------------------------------------------------------------------
// What must NOT dispatch. A transition engine that fires on non-transitions is worse than one
// that fires on none: a consumer retires rows that are still actionable.
// ---------------------------------------------------------------------------------------------

it('adds no event when a call changes no state', function (Closure $make, Closure $arrange, Closure $act): void {
    $listener = transitionListener();
    $store = $make();
    $arrange($store);

    // Baselined AFTER arrangement, because arranging legitimately transitions receipts and emits.
    // Asserting an empty log here instead would be a test that only passes while the feature is
    // missing — it would break the moment issuance started dispatching, which is the point.
    $baseline = count($listener->events);
    $act($store);

    expect($listener->events)->toHaveCount($baseline);
})->with('transition stores')->with([
    'a re-issued identical binding is idempotent, not a second issuance' => [
        fn (ApprovalReceiptStore $store) => $store->issue(transitionReceipt()),
        function (ApprovalReceiptStore $store): void {
            expect($store->issue(transitionReceipt())->outcome)->toBe(ApprovalOutcome::Existing);
        },
    ],
    'a decision on an unknown receipt id' => [
        fn (ApprovalReceiptStore $store) => null,
        function (ApprovalReceiptStore $store): void {
            expect($store->approve('receipt-missing', 'call-transition', 'user:42', transitionAt())->outcome)
                ->toBe(ApprovalOutcome::NotFound);
        },
    ],
    'a decision naming the wrong tool call' => [
        fn (ApprovalReceiptStore $store) => $store->issue(transitionReceipt()),
        function (ApprovalReceiptStore $store): void {
            expect($store->approve('receipt-transition', 'call-somewhere-else', 'user:42', transitionAt())->outcome)
                ->toBe(ApprovalOutcome::Mismatch);
        },
    ],
    'a second decision on an already-decided receipt' => [
        function (ApprovalReceiptStore $store): void {
            $store->issue(transitionReceipt());
            $store->approve('receipt-transition', 'call-transition', 'user:42', transitionAt());
        },
        function (ApprovalReceiptStore $store): void {
            expect($store->reject('receipt-transition', 'call-transition', 'user:9', transitionAt('12:06:00'))->outcome)
                ->toBe(ApprovalOutcome::InvalidState);
        },
    ],
    'a consumption whose binding does not match any receipt on the call' => [
        function (ApprovalReceiptStore $store): void {
            $receipt = transitionReceipt();
            $store->issue($receipt);
            $store->approve($receipt->id, $receipt->toolCallId, 'user:42', transitionAt());
        },
        function (ApprovalReceiptStore $store): void {
            // Consumption looks the receipt up BY fingerprint, so an unknown one finds nothing at
            // all — the outcome is NotFound, not Mismatch.
            expect($store->consume('call-transition', str_repeat('f', 64), transitionAt('12:06:00'))->outcome)
                ->toBe(ApprovalOutcome::NotFound);
        },
    ],
    'a consumption after the deadline lapsed' => [
        function (ApprovalReceiptStore $store): void {
            $receipt = transitionReceipt(expiresAt: new DateTimeImmutable('2026-08-01 12:01:00', new DateTimeZone('UTC')));
            $store->issue($receipt);
            $store->approve($receipt->id, $receipt->toolCallId, 'user:42', transitionAt('12:00:30'));
        },
        function (ApprovalReceiptStore $store): void {
            expect($store->consume('call-transition', hash('sha256', 'receipt-transition'), transitionAt('13:00:00'))->outcome)
                ->toBe(ApprovalOutcome::Expired);
        },
    ],
]);

it('stays silent through validate(), which reports the Approved outcome without transitioning', function (Closure $make): void {
    $store = $make();
    $receipt = transitionReceipt();
    $store->issue($receipt);
    $store->approve($receipt->id, $receipt->toolCallId, 'user:42', transitionAt());

    // The trap: validate() returns ApprovalOutcome::Approved for a receipt it did not touch, so an
    // implementation that keys dispatch on the outcome enum rather than on the write would fire
    // here — every execution-side check would retire a row that is still live.
    $listener = transitionListener();

    expect($store->validate($receipt->toolCallId, $receipt->bindingFingerprint, transitionAt('12:06:00'))->outcome)
        ->toBe(ApprovalOutcome::Approved)
        ->and($listener->events)->toBe([]);
})->with('transition stores');

it('dispatches nothing for a receipt that merely lapsed', function (Closure $make): void {
    // ADR 0029 §1 and ADR 0031's rejected alternatives: expiry has no transition moment and no
    // sweeper writes one. The issue lists "expired" as a fifth transition; there is no instant at
    // which it happens, so there is nothing to dispatch. A consumer compares expiresAt to its own
    // clock, exactly as the status read requires.
    $listener = transitionListener();
    $store = $make();
    $store->issue(transitionReceipt(expiresAt: new DateTimeImmutable('2026-08-01 12:01:00', new DateTimeZone('UTC'))));

    // Issuance itself legitimately emits; what must add nothing is the deadline passing and the
    // reads that observe it. Reads are observational (ADR 0031) and cannot become a write path.
    $baseline = count($listener->events);
    $store->findForToolCall('call-transition');
    $store->find('receipt-transition');
    $store->validate('call-transition', hash('sha256', 'receipt-transition'), transitionAt('13:00:00'));

    expect($listener->events)->toHaveCount($baseline)
        ->and($listener->statuses())->toBe(['pending']);
})->with('transition stores');

// ---------------------------------------------------------------------------------------------
// When it dispatches, and what it may carry.
// ---------------------------------------------------------------------------------------------

it('dispatches only after the security-state transaction commits', function (): void {
    $listener = transitionListener();
    $store = transitionDatabaseStore();
    $receipt = transitionReceipt();

    $store->issue($receipt);
    $store->approve($receipt->id, $receipt->toolCallId, 'user:42', transitionAt());

    // Dispatching inside the locked transaction would let a listener observe a transition that a
    // rollback then discards, and a retry would dispatch twice for one commit. Every listener call
    // must therefore see no open transaction.
    expect($listener->transactionLevels)->toBe([0, 0])
        ->and($listener->events)->toHaveCount(2);
});

it('carries identity and status and nothing else', function (): void {
    // ADR 0008: an event crossing into a listener is a context release. The payload may not become
    // a second disclosure path for the action facts the status read withholds, so the property set
    // is asserted whole — adding approvalContext, provenance, a binding fingerprint or an approver
    // identity to this event has to be a deliberate decision that fails this test first.
    // Public only: the payload is what crosses to a listener. A private memo added later is not
    // part of that contract and must not fail this test.
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(ApprovalReceiptTransitioned::class))->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    sort($properties);

    expect($properties)->toBe(['capability', 'occurredAt', 'receiptId', 'status', 'toolCallId']);
});

it('does not carry an approval context even when the receipt has one', function (Closure $make): void {
    $listener = transitionListener();
    $store = $make();
    $store->issue(transitionReceipt(approvalContext: ['tenant_id' => 7, 'conversation_id' => 'c-1']));

    // Belt and braces on the reflection guard: the scope the application bound is the most
    // tempting thing to smuggle onto the event, because a queue would find it convenient.
    $encoded = json_encode($listener->events[0]);

    expect($encoded)->not->toContain('tenant_id')
        ->and($encoded)->not->toContain('c-1');
})->with('transition stores');

it('transitions normally when no dispatcher is configured', function (): void {
    // Both stores take the dispatcher optionally, and an application that never wires one must
    // still be able to decide receipts.
    $store = new DatabaseApprovalReceiptStore(app(DatabaseManager::class)->connection());
    $memory = new InMemoryApprovalReceiptStore;
    $at = new DateTimeImmutable('2026-08-01 12:05:00', new DateTimeZone('UTC'));

    $receipt = transitionReceipt();
    $store->issue($receipt);
    $memory->issue($receipt);

    expect($store->approve($receipt->id, $receipt->toolCallId, 'user:42', $at)->outcome)
        ->toBe(ApprovalOutcome::Approved)
        ->and($memory->approve($receipt->id, $receipt->toolCallId, 'user:42', $at)->outcome)
        ->toBe(ApprovalOutcome::Approved);
});

// ---------------------------------------------------------------------------------------------
// Post-commit dispatch, proven rather than inferred.
// ---------------------------------------------------------------------------------------------

it('dispatches only what a separate connection can already see committed', function (): void {
    // transactionLevel() === 0 shows the store's transaction is closed; it does not show the write
    // is durable, and it would still read 0 for a dispatch from a finally block after a rollback.
    // A genuinely separate connection to the same file can only observe committed rows, so what a
    // listener sees through it is what survived the commit.
    $file = tempnam(sys_get_temp_dir(), 'verdict-transition-').'.sqlite';
    touch($file);

    try {
        config()->set('database.connections.transition_probe', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false,
        ]);
        config()->set('database.connections.transition_observer', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false,
        ]);

        $db = app(DatabaseManager::class);
        transitionEventSchema($db->connection('transition_probe')->getSchemaBuilder());

        $seen = [];
        app(Dispatcher::class)->listen(
            ApprovalReceiptTransitioned::class,
            function (ApprovalReceiptTransitioned $event) use ($db, &$seen): void {
                $row = $db->connection('transition_observer')
                    ->table(verdictTable('approvals'))->where('id', $event->receiptId)->first();

                $seen[] = [$event->status->value, $row?->status];
            },
        );

        $store = new DatabaseApprovalReceiptStore(
            connection: $db->connection('transition_probe'),
            events: app(Dispatcher::class),
        );

        $receipt = transitionReceipt();
        $store->issue($receipt);
        $store->approve($receipt->id, $receipt->toolCallId, 'user:42', transitionAt());

        // Every event's status is already the committed status on an outside connection.
        expect($seen)->toBe([['pending', 'pending'], ['approved', 'approved']]);
    } finally {
        app(DatabaseManager::class)->purge('transition_observer');
        app(DatabaseManager::class)->purge('transition_probe');
        @unlink($file);
    }
});

it('dispatches once for one committed transition, even when the transaction is retried', function (): void {
    // On its own sqlite file rather than the default connection: the flaky wrapper below is a
    // SQLiteConnection, and building one around whatever PDO the lane happens to supply emits
    // SQLite syntax at PostgreSQL. What is under test — that dispatch sits outside the retried
    // closure — is engine-independent, so pinning the engine costs no coverage.
    $file = tempnam(sys_get_temp_dir(), 'verdict-retry-').'.sqlite';
    touch($file);

    try {
        config()->set('database.connections.transition_retry', [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false,
        ]);

        $db = app(DatabaseManager::class);
        $real = $db->connection('transition_retry');
        transitionEventSchema($real->getSchemaBuilder());

        // A connection whose armed transaction runs the work, rolls it back, and reports a
        // concurrency conflict — so TransactionRetry runs the closure a second time. An
        // implementation that dispatches inside the closure, or buffers inside it and flushes
        // after, emits twice for the single commit that actually happened.
        $flaky = new class($real->getPdo(), $real->getDatabaseName(), $real->getTablePrefix(), $real->getConfig()) extends SQLiteConnection
        {
            public int $attempts = 0;

            public bool $armed = false;

            public function transaction(Closure $callback, $attempts = 1): mixed
            {
                $this->attempts++;

                if ($this->armed) {
                    $this->armed = false;

                    try {
                        parent::transaction(function () use ($callback) {
                            $callback();

                            throw new RuntimeException('forced rollback');
                        });
                    } catch (RuntimeException) {
                        // Rolled back: nothing this attempt wrote survives.
                    }

                    throw new PDOException('deadlock detected');
                }

                return parent::transaction($callback, $attempts);
            }
        };

        $listener = transitionListener();
        $store = new DatabaseApprovalReceiptStore(connection: $flaky, events: app(Dispatcher::class));
        $receipt = transitionReceipt();
        $store->issue($receipt);

        // Armed only now, so it is the APPROVAL that retries. Arming from construction would let
        // issuance absorb the forced conflict and leave approve() running exactly once — a retry
        // test that never retries the transition it names.
        $baseline = count($listener->events);
        $attemptsBefore = $flaky->attempts;
        $flaky->armed = true;

        $transition = $store->approve($receipt->id, $receipt->toolCallId, 'user:42', transitionAt());

        expect($transition->outcome)->toBe(ApprovalOutcome::Approved)
            ->and($flaky->attempts - $attemptsBefore)->toBe(2)
            ->and($listener->events)->toHaveCount($baseline + 1)
            ->and($listener->events[$baseline]->status)->toBe(ApprovalReceiptStatus::Approved);
    } finally {
        app(DatabaseManager::class)->purge('transition_retry');
        @unlink($file);
    }
});

it('emits once when a second decision arrives after the first resolved the receipt', function (Closure $make): void {
    $listener = transitionListener();
    $store = $make();
    $receipt = transitionReceipt();
    $store->issue($receipt);

    $baseline = count($listener->events);
    $first = $store->approve($receipt->id, $receipt->toolCallId, 'operator:a', transitionAt());
    $second = $store->reject($receipt->id, $receipt->toolCallId, 'operator:b', transitionAt('12:06:00'));

    // Sequential, and named that way on purpose: this is the ordinary loser path, not evidence
    // about a race. Real contention is proven by the concurrency matrix against separately
    // connected contenders, which this file does not attempt. What it does prove is that a refused
    // second decision announces nothing — two events would tell a queue the row resolved twice.
    expect($first->outcome)->toBe(ApprovalOutcome::Approved)
        ->and($second->outcome)->toBe(ApprovalOutcome::InvalidState)
        ->and($listener->events)->toHaveCount($baseline + 1)
        ->and($listener->events[$baseline]->status)->toBe(ApprovalReceiptStatus::Approved);
})->with('transition stores');

// ---------------------------------------------------------------------------------------------
// Wiring, and the neighbouring signal.
// ---------------------------------------------------------------------------------------------

it('dispatches from the container-resolved store, not only a hand-built one', function (): void {
    // The store an application actually gets is the one the service provider wires; a dispatcher
    // passed only in these tests' constructors would prove nothing about a real deployment.
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    app()->forgetInstance(ApprovalReceiptStore::class);

    $listener = transitionListener();
    $store = app(ApprovalReceiptStore::class);

    expect($store)->toBeInstanceOf(DatabaseApprovalReceiptStore::class);

    $receipt = transitionReceipt();
    $store->issue($receipt);
    $store->approve($receipt->id, $receipt->toolCallId, 'user:42', transitionAt());

    expect($listener->statuses())->toBe(['pending', 'approved']);
});

it('adds no event when an insert is rejected by the unique key', function (): void {
    $store = transitionDatabaseStore();
    $store->issue(transitionReceipt());

    $listener = transitionListener();

    // Reusing the id under a DIFFERENT capability is what actually reaches the store's
    // UniqueConstraintViolationException recovery: the binding lookup misses, so the insert is
    // attempted and the primary key rejects it. Re-issuing an identical binding would never get
    // there — the lookup finds the row and returns Existing without inserting.
    $collision = transitionReceipt(capability: 'orders.refund');

    expect($store->issue($collision)->outcome)->toBe(ApprovalOutcome::NotFound)
        ->and($listener->events)->toBe([]);
});

it('announces a changed proposal and its issuance as two separate signals', function (Closure $make): void {
    $listener = transitionListener();
    $changed = [];
    app(Dispatcher::class)->listen(
        ApprovalProposalChangedUnderOpenReceipt::class,
        function (ApprovalProposalChangedUnderOpenReceipt $event) use (&$changed): void {
            $changed[] = $event->newReceiptId;
        },
    );

    $store = $make();
    $store->issue(transitionReceipt('receipt-original'));
    $store->issue(transitionReceipt('receipt-revised'));

    // Both fire for the second issuance, deliberately: one says "a receipt now exists", the other
    // says "it collides with an open one". A consumer must not treat them as two invalidations of
    // the same row.
    expect($listener->statuses())->toBe(['pending', 'pending'])
        ->and($changed)->toBe(['receipt-revised']);
})->with('transition stores');
