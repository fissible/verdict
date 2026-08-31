<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalLookupOutcome;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptLookup;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusLookup;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\DatabaseApprovalStatusReader;
use Fissible\Verdict\Approvals\DistinguishingStoreBackedApprovalStatusReader;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalStatusReader;
use Fissible\Verdict\Approvals\StoreBackedApprovalStatusReader;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\DistinguishesReceiptCollisions;
use Fissible\Verdict\Contracts\DistinguishesStatusCollisions;
use Fissible\Verdict\Tests\Support\FrozenClock;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Issue #425. A tool-call id is a provider identifier, not a Verdict key: receipts are unique on
 * (tool_call_id, capability, binding_fingerprint), so two receipts legitimately share one
 * tool-call id — a cross-capability collision, or a proposal that changed under an open receipt.
 * `findForToolCall()` cannot express that: its null means both "none" and "more than one".
 *
 * The fix is additive. `ApprovalReceiptStore` is Stable through 1.0, so its signature and its
 * ambiguous semantics are untouched; the three-outcome read arrives as an opt-in interface the
 * shipped stores and readers implement. A custom store that has not adopted it keeps working
 * exactly as before — and, critically, is never made to *look* as though it had adopted it.
 */
function collisionSchema(Builder $schema): void
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
    collisionSchema(app(DatabaseManager::class)->connection()->getSchemaBuilder());
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('approvals'));
});

function collisionReceipt(
    string $id,
    string $toolCallId = 'call-collision',
    string $capability = 'orders.cancel',
    ?DateTimeImmutable $createdAt = null,
    ?string $bindingFingerprint = null,
    ?array $approvalContext = null,
): ApprovalReceipt {
    $now = $createdAt ?? new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    return new ApprovalReceipt(
        id: $id,
        toolCallId: $toolCallId,
        capability: $capability,
        bindingFingerprint: $bindingFingerprint ?? hash('sha256', $id),
        provenance: null,
        approvalContext: $approvalContext,
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm cancellation.',
        expiresAt: $now->modify('+15 minutes'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $now,
        updatedAt: $now,
    );
}

/**
 * A store on the Stable contract alone — no opt-in seam, the old signature, the old ambiguous
 * null. This is the shape every existing custom store already has, and nothing in this change may
 * require it to move.
 */
function legacyCollisionStore(): ApprovalReceiptStore
{
    return new class implements ApprovalReceiptStore
    {
        private InMemoryApprovalReceiptStore $inner;

        public array $findCalls = [];

        public function __construct()
        {
            $this->inner = new InMemoryApprovalReceiptStore;
        }

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
            $this->findCalls[] = $receiptId;

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
    };
}

/**
 * The same store after adopting the seam: still a custom store the provider cannot recognise by
 * class, now declaring it can tell a collision from an absence.
 */
function adoptingCollisionStore(): ApprovalReceiptStore&DistinguishesReceiptCollisions
{
    return new class implements ApprovalReceiptStore, DistinguishesReceiptCollisions
    {
        private InMemoryApprovalReceiptStore $inner;

        public function __construct()
        {
            $this->inner = new InMemoryApprovalReceiptStore;
        }

        public function issue(ApprovalReceipt $receipt): ApprovalTransition
        {
            return $this->inner->issue($receipt);
        }

        public function findForToolCall(string $toolCallId): ?ApprovalReceipt
        {
            return $this->inner->findForToolCall($toolCallId);
        }

        public function lookupForToolCall(string $toolCallId): ApprovalReceiptLookup
        {
            return $this->inner->lookupForToolCall($toolCallId);
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
    };
}

/** @return array{ApprovalReceiptStore&DistinguishesReceiptCollisions, ApprovalStatusReader&DistinguishesStatusCollisions} */
function collisionDatabasePair(): array
{
    $store = new DatabaseApprovalReceiptStore(app(DatabaseManager::class)->connection());

    return [$store, new DatabaseApprovalStatusReader($store)];
}

/** @return array{ApprovalReceiptStore&DistinguishesReceiptCollisions, ApprovalStatusReader&DistinguishesStatusCollisions} */
function collisionInMemoryPair(): array
{
    $store = new InMemoryApprovalReceiptStore;

    return [$store, new InMemoryApprovalStatusReader($store)];
}

/** @return array{ApprovalReceiptStore&DistinguishesReceiptCollisions, ApprovalStatusReader&DistinguishesStatusCollisions} */
function collisionStoreBackedPair(): array
{
    $store = new InMemoryApprovalReceiptStore;

    return [$store, new DistinguishingStoreBackedApprovalStatusReader($store)];
}

dataset('collision pairs', [
    'database' => [fn (): array => collisionDatabasePair()],
    'in-memory' => [fn (): array => collisionInMemoryPair()],
    'store-backed over a distinguishing store' => [fn (): array => collisionStoreBackedPair()],
]);

// ---------------------------------------------------------------------------------------------
// The Stable contract is unchanged. These are the tests that make the compatibility claim
// checkable rather than a sentence in a changelog.
// ---------------------------------------------------------------------------------------------

it('leaves the Stable receipt-store signature exactly as it was', function (): void {
    $method = new ReflectionMethod(ApprovalReceiptStore::class, 'findForToolCall');

    // ApprovalReceiptStore is documented Stable through 1.0 in docs/extension-contract-stability.md.
    // If this assertion ever needs changing, the contract promise is what is being broken.
    expect((string) $method->getReturnType())->toBe('?'.ApprovalReceipt::class)
        ->and($method->getNumberOfParameters())->toBe(1)
        ->and((string) $method->getParameters()[0]->getType())->toBe('string');
});

it('does not require the opt-in seam of anything on the Stable contract', function (): void {
    // A store may implement ApprovalReceiptStore and nothing else. If the collision seam were
    // folded into the Stable contract instead of added beside it, this class would not declare.
    $store = legacyCollisionStore();

    expect($store)->toBeInstanceOf(ApprovalReceiptStore::class)
        ->and($store)->not->toBeInstanceOf(DistinguishesReceiptCollisions::class);
});

it('keeps a legacy store working end to end, collision included', function (): void {
    app()->instance(Clock::class, new FrozenClock);
    $store = legacyCollisionStore();
    app()->instance(ApprovalReceiptStore::class, $store);
    $manager = app(ApprovalManager::class);

    $store->issue(collisionReceipt('receipt-one'));
    expect($manager->challengeForToolCall('call-collision')?->receiptId)->toBe('receipt-one');

    // The old ambiguous null: a collision reads as no receipt. That is the behaviour a legacy
    // store keeps until it adopts the seam — unchanged, not silently repaired and not broken.
    $store->issue(collisionReceipt('receipt-two', capability: 'orders.refund'));

    expect($store->findForToolCall('call-collision'))->toBeNull()
        ->and($manager->challengeForToolCall('call-collision'))->toBeNull();
});

it('leaves the status-reader tool-call read on its existing nullable shape', function (Closure $pair): void {
    [$store, $reader] = $pair();
    $store->issue(collisionReceipt('receipt-one'));

    expect($reader->statusForToolCall('call-collision'))->toBeInstanceOf(ApprovalStatusView::class)
        ->and($reader->statusForToolCall('call-collision')?->receiptId)->toBe('receipt-one');

    $store->issue(collisionReceipt('receipt-two', capability: 'orders.refund'));

    // Still the documented ambiguity on this method. Callers that want the distinction move to
    // the opt-in seam; callers that do not are not forced to change.
    expect($reader->statusForToolCall('call-collision'))->toBeNull()
        ->and($store->findForToolCall('call-collision'))->toBeNull();
})->with('collision pairs');

// ---------------------------------------------------------------------------------------------
// The opt-in seam.
// ---------------------------------------------------------------------------------------------

it('declares the opt-in seam on the shipped stores and readers', function (Closure $pair): void {
    [$store, $reader] = $pair();

    expect($store)->toBeInstanceOf(DistinguishesReceiptCollisions::class)
        ->and($reader)->toBeInstanceOf(DistinguishesStatusCollisions::class);
})->with('collision pairs');

it('reports absence as its own outcome, carrying no receipt and no ids', function (Closure $pair): void {
    [$store] = $pair();

    $lookup = $store->lookupForToolCall('call-never-issued');

    expect($lookup)->toBeInstanceOf(ApprovalReceiptLookup::class)
        ->and($lookup->outcome)->toBe(ApprovalLookupOutcome::Absent)
        ->and($lookup->receipt)->toBeNull()
        ->and($lookup->receiptIds)->toBe([])
        ->and($lookup->count())->toBe(0);
})->with('collision pairs');

it('reports a single receipt with the receipt and its id', function (Closure $pair): void {
    [$store] = $pair();
    $store->issue(collisionReceipt('receipt-only'));

    $lookup = $store->lookupForToolCall('call-collision');

    expect($lookup->outcome)->toBe(ApprovalLookupOutcome::Single)
        ->and($lookup->receipt?->id)->toBe('receipt-only')
        ->and($lookup->receiptIds)->toBe(['receipt-only'])
        ->and($lookup->count())->toBe(1);
})->with('collision pairs');

it('reports multiplicity as a distinct outcome that is not absence', function (Closure $pair): void {
    [$store] = $pair();
    $store->issue(collisionReceipt('receipt-one'));
    $store->issue(collisionReceipt('receipt-two', capability: 'orders.refund'));

    $multiple = $store->lookupForToolCall('call-collision');
    $absent = $store->lookupForToolCall('call-never-issued');

    // The acceptance criterion of #425, stated directly: these two reads must not agree — and on
    // the old method, which is untouched, they still do.
    expect($multiple->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($multiple)->not->toEqual($absent)
        ->and($multiple->count())->toBe(2)
        ->and($multiple->receiptIds)->toEqualCanonicalizing(['receipt-one', 'receipt-two'])
        ->and($store->findForToolCall('call-collision'))->toBeNull()
        ->and($store->findForToolCall('call-never-issued'))->toBeNull();
})->with('collision pairs');

it('never hands back a canonical receipt under multiplicity', function (Closure $pair): void {
    [$store] = $pair();
    $store->issue(collisionReceipt('receipt-one'));
    $store->issue(collisionReceipt('receipt-two', capability: 'orders.refund'));

    // A silent pick would let a consumer act on one of two colliding receipts without ever
    // learning the other exists — the failure mode the multiplicity outcome exists to expose.
    expect($store->lookupForToolCall('call-collision')->receipt)->toBeNull();
})->with('collision pairs');

it('reports multiplicity for the same capability under a changed binding', function (Closure $pair): void {
    [$store, $reader] = $pair();

    // The other way one tool call legitimately holds two receipts, and the one #311 item 1 named:
    // the proposal changed while a receipt was already open, so the capability is identical and
    // only the binding fingerprint differs.
    $store->issue(collisionReceipt('receipt-original', bindingFingerprint: str_repeat('a', 64)));
    $store->issue(collisionReceipt('receipt-changed', bindingFingerprint: str_repeat('b', 64)));

    expect($store->lookupForToolCall('call-collision')->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($store->lookupForToolCall('call-collision')->receipt)->toBeNull()
        ->and($store->lookupForToolCall('call-collision')->receiptIds)
        ->toEqualCanonicalizing(['receipt-original', 'receipt-changed'])
        ->and($reader->statusLookupForToolCall('call-collision')->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($reader->statusLookupForToolCall('call-collision')->status)->toBeNull();
})->with('collision pairs');

it('counts and names every colliding receipt, not the first two it finds', function (Closure $pair): void {
    [$store] = $pair();
    $store->issue(collisionReceipt('receipt-a'));
    $store->issue(collisionReceipt('receipt-b', capability: 'orders.refund'));
    $store->issue(collisionReceipt('receipt-c', capability: 'orders.credit'));

    // The old read short-circuits at limit(2) and may keep doing so. The seam may not: a
    // collision of three reported as a collision of two under-counts the damage.
    $lookup = $store->lookupForToolCall('call-collision');

    expect($lookup->count())->toBe(3)
        ->and($lookup->receiptIds)->toEqualCanonicalizing(['receipt-a', 'receipt-b', 'receipt-c']);
})->with('collision pairs');

it('orders the colliding ids by creation then id, so a queue renders them stably', function (Closure $pair): void {
    [$store] = $pair();
    $earlier = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
    $later = new DateTimeImmutable('2026-08-01 12:05:00', new DateTimeZone('UTC'));

    // The lexically first id is issued first but created last, and the two that tie on creation
    // are issued in reverse. Insertion order alone gives a, z, b; id order alone gives a, b, z;
    // creation order alone leaves the b/z tie unresolved. Only created_at-then-id gives b, z, a.
    $store->issue(collisionReceipt('receipt-a', createdAt: $later));
    $store->issue(collisionReceipt('receipt-z', capability: 'orders.credit', createdAt: $earlier));
    $store->issue(collisionReceipt('receipt-b', capability: 'orders.refund', createdAt: $earlier));

    expect($store->lookupForToolCall('call-collision')->receiptIds)
        ->toBe(['receipt-b', 'receipt-z', 'receipt-a']);
})->with('collision pairs');

it('keeps receipts on other tool calls out of the lookup', function (Closure $pair): void {
    [$store] = $pair();
    $store->issue(collisionReceipt('receipt-here'));
    $store->issue(collisionReceipt('receipt-elsewhere', toolCallId: 'call-other'));

    expect($store->lookupForToolCall('call-collision')->receiptIds)->toBe(['receipt-here'])
        ->and($store->lookupForToolCall('call-other')->receiptIds)->toBe(['receipt-elsewhere']);
})->with('collision pairs');

it('carries the same three outcomes through the status seam', function (Closure $pair): void {
    [$store, $reader] = $pair();

    $absent = $reader->statusLookupForToolCall('call-collision');
    expect($absent)->toBeInstanceOf(ApprovalStatusLookup::class)
        ->and($absent->outcome)->toBe(ApprovalLookupOutcome::Absent)
        ->and($absent->status)->toBeNull()
        ->and($absent->receiptIds)->toBe([])
        ->and($absent->count())->toBe(0);

    $store->issue(collisionReceipt('receipt-one'));

    $single = $reader->statusLookupForToolCall('call-collision');
    expect($single->outcome)->toBe(ApprovalLookupOutcome::Single)
        ->and($single->status?->receiptId)->toBe('receipt-one')
        ->and($single->receiptIds)->toBe(['receipt-one'])
        ->and($single->count())->toBe(1);

    $store->issue(collisionReceipt('receipt-two', capability: 'orders.refund'));

    $multiple = $reader->statusLookupForToolCall('call-collision');
    expect($multiple->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($multiple->status)->toBeNull()
        ->and($multiple->count())->toBe(2)
        ->and($multiple->receiptIds)->toEqualCanonicalizing(['receipt-one', 'receipt-two'])
        ->and($multiple)->not->toEqual($absent);
})->with('collision pairs');

it('hydrates the single tool-call status view completely, not just its id', function (Closure $pair): void {
    [$store, $reader] = $pair();
    $receipt = collisionReceipt('receipt-hydrated', approvalContext: ['tenant_id' => 7, 'conversation_id' => 'c-1']);
    $store->issue($receipt);
    $store->approve($receipt->id, $receipt->toolCallId, 'user:42', new DateTimeImmutable('2026-08-01 12:03:00', new DateTimeZone('UTC')));

    // The seam is a second hydration route, so a Single result carrying the right id and wrong
    // fields — a hard-coded Pending, a dropped context — would otherwise pass. Decided first, so
    // the status cannot be right by accident.
    $view = $reader->statusLookupForToolCall('call-collision')->status;

    expect($view)->toBeInstanceOf(ApprovalStatusView::class)
        ->and($view?->receiptId)->toBe('receipt-hydrated')
        ->and($view?->toolCallId)->toBe('call-collision')
        ->and($view?->capability)->toBe('orders.cancel')
        ->and($view?->status)->toBe(ApprovalReceiptStatus::Approved)
        ->and($view?->reason)->toBe('Confirm cancellation.')
        ->and($view?->approvedBy)->toBe('user:42')
        ->and($view?->approvedAt?->format(DATE_ATOM))->toBe('2026-08-01T12:03:00+00:00')
        ->and($view?->rejectedBy)->toBeNull()
        ->and($view?->rejectedAt)->toBeNull()
        ->and($view?->consumedAt)->toBeNull()
        ->and($view?->expiresAt->format(DATE_ATOM))->toBe($receipt->expiresAt->format(DATE_ATOM))
        ->and($view?->createdAt->format(DATE_ATOM))->toBe($receipt->createdAt->format(DATE_ATOM))
        ->and($view?->approvalContext)->toBe(['tenant_id' => 7, 'conversation_id' => 'c-1']);
})->with('collision pairs');

it('still reads each colliding receipt by its own id', function (Closure $pair): void {
    [$store, $reader] = $pair();
    $store->issue(collisionReceipt('receipt-one'));
    $store->issue(collisionReceipt('receipt-two', capability: 'orders.refund'));

    // Multiplicity is a property of the tool-call read alone. Addressing a receipt by id was
    // never ambiguous and must stay unaffected — that is the escape hatch the queue uses next.
    expect($reader->statusFor('receipt-one')?->receiptId)->toBe('receipt-one')
        ->and($reader->statusFor('receipt-two')?->receiptId)->toBe('receipt-two')
        ->and($store->find('receipt-one')?->capability)->toBe('orders.cancel')
        ->and($store->find('receipt-two')?->capability)->toBe('orders.refund');
})->with('collision pairs');

// ---------------------------------------------------------------------------------------------
// The store-backed reader over a store that has not adopted the seam.
// ---------------------------------------------------------------------------------------------

it('does not advertise the status seam over a store that cannot answer it', function (): void {
    $store = legacyCollisionStore();
    $reader = new StoreBackedApprovalStatusReader($store);
    $store->issue(collisionReceipt('receipt-one'));

    // instanceof is the discovery mechanism a consumer is told to trust, so a reader that carries
    // the interface and throws would be a false positive on exactly that probe. A reader either
    // can distinguish a collision or does not claim to.
    expect($reader)->not->toBeInstanceOf(DistinguishesStatusCollisions::class)
        ->and(method_exists($reader, 'statusLookupForToolCall'))->toBeFalse()
        // Everything it did before still works over the same legacy store.
        ->and($reader->statusForToolCall('call-collision')?->receiptId)->toBe('receipt-one')
        ->and($reader->statusFor('receipt-one')?->receiptId)->toBe('receipt-one');
});

it('pairs a legacy store with a reader that claims no collision capability', function (): void {
    // The container is where a custom store actually meets a reader, so the opt-in claim has to
    // hold there and not only on a hand-built pair.
    $store = legacyCollisionStore();
    app()->instance(ApprovalReceiptStore::class, $store);
    app()->forgetInstance(ApprovalStatusReader::class);

    $reader = app(ApprovalStatusReader::class);

    expect($reader)->toBeInstanceOf(StoreBackedApprovalStatusReader::class)
        ->and($reader)->not->toBeInstanceOf(DistinguishesStatusCollisions::class);
});

it('pairs a store that adopted the seam with a reader that carries it', function (): void {
    // A custom store — not a shipped class, so the provider must reach the store-backed branch,
    // the one whose choice is driven by the opt-in interface rather than by a class check.
    $store = adoptingCollisionStore();
    app()->instance(ApprovalReceiptStore::class, $store);
    app()->forgetInstance(ApprovalStatusReader::class);

    $reader = app(ApprovalStatusReader::class);
    $store->issue(collisionReceipt('receipt-one'));
    $store->issue(collisionReceipt('receipt-two', capability: 'orders.refund'));

    expect($store)->not->toBeInstanceOf(DatabaseApprovalReceiptStore::class)
        ->and($store)->not->toBeInstanceOf(InMemoryApprovalReceiptStore::class)
        ->and($reader)->toBeInstanceOf(DistinguishingStoreBackedApprovalStatusReader::class)
        ->and($reader)->toBeInstanceOf(DistinguishesStatusCollisions::class)
        ->and($reader->statusLookupForToolCall('call-collision')->outcome)
        ->toBe(ApprovalLookupOutcome::Multiple)
        ->and($reader->statusLookupForToolCall('call-collision')->receiptIds)
        ->toEqualCanonicalizing(['receipt-one', 'receipt-two'])
        // The unchanged reads still work through the same paired reader.
        ->and($reader->statusFor('receipt-one')?->receiptId)->toBe('receipt-one')
        ->and($reader->statusForToolCall('call-collision'))->toBeNull();
});

it('rides the store seam without hydrating ids it was not given a receipt for', function (): void {
    $store = new class implements ApprovalReceiptStore, DistinguishesReceiptCollisions
    {
        /** @var list<string> */
        public array $findCalls = [];

        public function issue(ApprovalReceipt $receipt): ApprovalTransition
        {
            throw new RuntimeException('not exercised');
        }

        public function findForToolCall(string $toolCallId): ?ApprovalReceipt
        {
            return null;
        }

        public function lookupForToolCall(string $toolCallId): ApprovalReceiptLookup
        {
            return $toolCallId === 'call-collided'
                ? ApprovalReceiptLookup::multiple(['r-1', 'r-2', 'r-3'])
                : ApprovalReceiptLookup::absent();
        }

        public function find(string $receiptId): ?ApprovalReceipt
        {
            $this->findCalls[] = $receiptId;

            return null;
        }

        public function approve(string $receiptId, string $toolCallId, string $approvedBy, DateTimeImmutable $at): ApprovalTransition
        {
            throw new RuntimeException('not exercised');
        }

        public function reject(string $receiptId, string $toolCallId, string $rejectedBy, DateTimeImmutable $at): ApprovalTransition
        {
            throw new RuntimeException('not exercised');
        }

        public function validate(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
        {
            throw new RuntimeException('not exercised');
        }

        public function consume(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
        {
            throw new RuntimeException('not exercised');
        }
    };

    $reader = new DistinguishingStoreBackedApprovalStatusReader($store);

    expect($reader->statusLookupForToolCall('call-collided')->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($reader->statusLookupForToolCall('call-collided')->receiptIds)->toBe(['r-1', 'r-2', 'r-3'])
        ->and($reader->statusLookupForToolCall('call-collided')->count())->toBe(3)
        ->and($reader->statusLookupForToolCall('call-collided')->status)->toBeNull()
        ->and($reader->statusLookupForToolCall('call-quiet')->outcome)->toBe(ApprovalLookupOutcome::Absent)
        // A null status alone would not prove the reader kept its hands off: it could call find()
        // on each id, discard the results, and still look correct.
        ->and($store->findCalls)->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// The value objects.
// ---------------------------------------------------------------------------------------------

it('refuses to build a multiplicity result that is not actually multiple', function (): void {
    // The outcome is load-bearing for consumers, so the value object may not be talked into
    // reporting Multiple for zero or one receipt. A repeated id is the same falsehood wearing a
    // list of length two: it names one receipt while counting two.
    expect(fn (): ApprovalReceiptLookup => ApprovalReceiptLookup::multiple([]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ApprovalReceiptLookup => ApprovalReceiptLookup::multiple(['only-one']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ApprovalReceiptLookup => ApprovalReceiptLookup::multiple(['dup', 'dup']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ApprovalStatusLookup => ApprovalStatusLookup::multiple([]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ApprovalStatusLookup => ApprovalStatusLookup::multiple(['only-one']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ApprovalStatusLookup => ApprovalStatusLookup::multiple(['dup', 'dup']))
        ->toThrow(InvalidArgumentException::class);
});

it('builds each outcome from its own named constructor', function (): void {
    $receipt = collisionReceipt('receipt-built');
    $single = ApprovalReceiptLookup::single($receipt);

    expect(ApprovalReceiptLookup::absent()->outcome)->toBe(ApprovalLookupOutcome::Absent)
        ->and(ApprovalReceiptLookup::absent()->receiptIds)->toBe([])
        ->and($single->outcome)->toBe(ApprovalLookupOutcome::Single)
        ->and($single->receipt)->toBe($receipt)
        // A single result names its receipt in receiptIds too, so a consumer that reads ids
        // never has to special-case the common outcome.
        ->and($single->receiptIds)->toBe(['receipt-built'])
        ->and(ApprovalReceiptLookup::multiple(['a', 'b'])->receipt)->toBeNull()
        ->and(ApprovalStatusLookup::absent()->status)->toBeNull()
        ->and(ApprovalStatusLookup::single(ApprovalStatusView::fromReceipt($receipt))->receiptIds)
        ->toBe(['receipt-built'])
        ->and(ApprovalStatusLookup::multiple(['a', 'b'])->status)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// The manager is deliberately not moved onto the seam.
// ---------------------------------------------------------------------------------------------

it('issues a challenge only for a single receipt, never for absence or a collision', function (): void {
    app()->instance(Clock::class, new FrozenClock);
    $store = app(ApprovalReceiptStore::class);
    $manager = app(ApprovalManager::class);

    expect($manager->challengeForToolCall('call-collision'))->toBeNull();

    $store->issue(collisionReceipt('receipt-one'));
    expect($manager->challengeForToolCall('call-collision')?->receiptId)->toBe('receipt-one');

    // A challenge names exactly one receipt, so a collision must produce none. The manager gets
    // that from the unchanged ambiguous read, which is why it needs no seam and works the same
    // over a store that has not adopted one.
    $store->issue(collisionReceipt('receipt-two', capability: 'orders.refund'));
    expect($manager->challengeForToolCall('call-collision'))->toBeNull();
});
