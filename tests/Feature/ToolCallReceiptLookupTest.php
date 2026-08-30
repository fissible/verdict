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
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalStatusReader;
use Fissible\Verdict\Approvals\StoreBackedApprovalStatusReader;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Issue #425. A tool-call id is a provider identifier, not a Verdict key: the receipts table is
 * unique on (tool_call_id, capability, binding_fingerprint), so two receipts legitimately share
 * one tool-call id. The read path used to collapse that multiplicity into the same `null` it
 * returns for "no receipt exists", which is what these tests forbid: absence, a single receipt,
 * and multiplicity are three outcomes a reviewer queue must be able to tell apart.
 *
 * The multiplicity result deliberately carries no receipt. Picking a canonical one would paper
 * over a real event — a cross-capability collision, or a proposal that changed under an open
 * receipt — which is the thing the queue needs to see.
 */
function lookupSchema(Builder $schema): void
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
    lookupSchema(app(DatabaseManager::class)->connection()->getSchemaBuilder());
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('approvals'));
});

function lookupReceipt(
    string $id,
    string $toolCallId = 'call-lookup',
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

/** @return array{ApprovalReceiptStore, ApprovalStatusReader} */
function lookupDatabasePair(): array
{
    $store = new DatabaseApprovalReceiptStore(app(DatabaseManager::class)->connection());

    return [$store, new DatabaseApprovalStatusReader($store)];
}

/** @return array{ApprovalReceiptStore, ApprovalStatusReader} */
function lookupInMemoryPair(): array
{
    $store = new InMemoryApprovalReceiptStore;

    return [$store, new InMemoryApprovalStatusReader($store)];
}

/**
 * The store-backed reader is the one every store without its own reader gets by default, so it
 * has to carry the distinction too.
 *
 * @return array{ApprovalReceiptStore, ApprovalStatusReader}
 */
function lookupStoreBackedPair(): array
{
    $store = new InMemoryApprovalReceiptStore;

    return [$store, new StoreBackedApprovalStatusReader($store)];
}

dataset('lookup pairs', [
    'database' => [fn (): array => lookupDatabasePair()],
    'in-memory' => [fn (): array => lookupInMemoryPair()],
    'store-backed' => [fn (): array => lookupStoreBackedPair()],
]);

it('reports absence as its own outcome, carrying no receipt and no ids', function (Closure $pair): void {
    [$store] = $pair();

    $lookup = $store->findForToolCall('call-never-issued');

    expect($lookup)->toBeInstanceOf(ApprovalReceiptLookup::class)
        ->and($lookup->outcome)->toBe(ApprovalLookupOutcome::Absent)
        ->and($lookup->receipt)->toBeNull()
        ->and($lookup->receiptIds)->toBe([])
        ->and($lookup->count())->toBe(0);
})->with('lookup pairs');

it('reports a single receipt with the receipt and its id', function (Closure $pair): void {
    [$store] = $pair();
    $store->issue(lookupReceipt('receipt-only'));

    $lookup = $store->findForToolCall('call-lookup');

    expect($lookup->outcome)->toBe(ApprovalLookupOutcome::Single)
        ->and($lookup->receipt?->id)->toBe('receipt-only')
        ->and($lookup->receiptIds)->toBe(['receipt-only'])
        ->and($lookup->count())->toBe(1);
})->with('lookup pairs');

it('reports multiplicity as a distinct outcome that is not absence', function (Closure $pair): void {
    [$store] = $pair();
    $store->issue(lookupReceipt('receipt-one'));
    $store->issue(lookupReceipt('receipt-two', capability: 'orders.refund'));

    $multiple = $store->findForToolCall('call-lookup');
    $absent = $store->findForToolCall('call-never-issued');

    // The acceptance criterion of #425, stated directly: these two reads must not agree.
    expect($multiple->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($multiple->outcome)->not->toBe($absent->outcome)
        ->and($multiple)->not->toEqual($absent)
        ->and($multiple->count())->toBe(2)
        ->and($multiple->receiptIds)->toEqualCanonicalizing(['receipt-one', 'receipt-two']);
})->with('lookup pairs');

it('never hands back a canonical receipt under multiplicity', function (Closure $pair): void {
    [$store] = $pair();
    $store->issue(lookupReceipt('receipt-one'));
    $store->issue(lookupReceipt('receipt-two', capability: 'orders.refund'));

    // A silent pick would let a consumer act on one of two colliding receipts without ever
    // learning the other exists — the failure mode the multiplicity outcome exists to expose.
    expect($store->findForToolCall('call-lookup')->receipt)->toBeNull();
})->with('lookup pairs');

it('counts and names every colliding receipt, not the first two it finds', function (Closure $pair): void {
    [$store] = $pair();
    $store->issue(lookupReceipt('receipt-a'));
    $store->issue(lookupReceipt('receipt-b', capability: 'orders.refund'));
    $store->issue(lookupReceipt('receipt-c', capability: 'orders.credit'));

    // The pre-#425 read short-circuited at limit(2). An implementation that keeps that bound
    // reports a collision of three as a collision of two, so the queue under-counts the damage.
    $lookup = $store->findForToolCall('call-lookup');

    expect($lookup->count())->toBe(3)
        ->and($lookup->receiptIds)->toEqualCanonicalizing(['receipt-a', 'receipt-b', 'receipt-c']);
})->with('lookup pairs');

it('orders the colliding ids by creation then id, so a queue renders them stably', function (Closure $pair): void {
    [$store] = $pair();
    $earlier = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
    $later = new DateTimeImmutable('2026-08-01 12:05:00', new DateTimeZone('UTC'));

    // The lexically first id is issued first but created last, and the two that tie on creation
    // are issued in reverse. Insertion order alone gives a, z, b; id order alone gives a, b, z;
    // creation order alone leaves the b/z tie unresolved. Only created_at-then-id gives b, z, a.
    $store->issue(lookupReceipt('receipt-a', createdAt: $later));
    $store->issue(lookupReceipt('receipt-z', capability: 'orders.credit', createdAt: $earlier));
    $store->issue(lookupReceipt('receipt-b', capability: 'orders.refund', createdAt: $earlier));

    expect($store->findForToolCall('call-lookup')->receiptIds)
        ->toBe(['receipt-b', 'receipt-z', 'receipt-a']);
})->with('lookup pairs');

it('reports multiplicity for the same capability under a changed binding', function (Closure $pair): void {
    [$store, $reader] = $pair();

    // The other way one tool call legitimately holds two receipts, and the one #311 item 1 named:
    // the proposal changed while a receipt was already open, so the capability is identical and
    // only the binding fingerprint differs. The unique key admits it; the read must report it.
    $store->issue(lookupReceipt('receipt-original', bindingFingerprint: str_repeat('a', 64)));
    $store->issue(lookupReceipt('receipt-changed', bindingFingerprint: str_repeat('b', 64)));

    expect($store->findForToolCall('call-lookup')->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($store->findForToolCall('call-lookup')->receipt)->toBeNull()
        ->and($store->findForToolCall('call-lookup')->receiptIds)
        ->toEqualCanonicalizing(['receipt-original', 'receipt-changed'])
        ->and($reader->statusForToolCall('call-lookup')->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($reader->statusForToolCall('call-lookup')->status)->toBeNull();
})->with('lookup pairs');

it('keeps receipts on other tool calls out of the lookup', function (Closure $pair): void {
    [$store] = $pair();
    $store->issue(lookupReceipt('receipt-here'));
    $store->issue(lookupReceipt('receipt-elsewhere', toolCallId: 'call-other'));

    expect($store->findForToolCall('call-lookup')->outcome)->toBe(ApprovalLookupOutcome::Single)
        ->and($store->findForToolCall('call-lookup')->receiptIds)->toBe(['receipt-here'])
        ->and($store->findForToolCall('call-other')->receiptIds)->toBe(['receipt-elsewhere']);
})->with('lookup pairs');

it('carries the same three outcomes through the status read', function (Closure $pair): void {
    [$store, $reader] = $pair();

    $absent = $reader->statusForToolCall('call-lookup');
    expect($absent)->toBeInstanceOf(ApprovalStatusLookup::class)
        ->and($absent->outcome)->toBe(ApprovalLookupOutcome::Absent)
        ->and($absent->status)->toBeNull()
        ->and($absent->receiptIds)->toBe([])
        ->and($absent->count())->toBe(0);

    $store->issue(lookupReceipt('receipt-one'));

    $single = $reader->statusForToolCall('call-lookup');
    expect($single->outcome)->toBe(ApprovalLookupOutcome::Single)
        ->and($single->status)->toBeInstanceOf(ApprovalStatusView::class)
        ->and($single->status?->receiptId)->toBe('receipt-one')
        ->and($single->receiptIds)->toBe(['receipt-one'])
        ->and($single->count())->toBe(1);

    $store->issue(lookupReceipt('receipt-two', capability: 'orders.refund'));

    $multiple = $reader->statusForToolCall('call-lookup');
    expect($multiple->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($multiple->status)->toBeNull()
        ->and($multiple->count())->toBe(2)
        ->and($multiple->receiptIds)->toEqualCanonicalizing(['receipt-one', 'receipt-two'])
        ->and($multiple)->not->toEqual($absent);
})->with('lookup pairs');

it('hydrates the single tool-call view completely, not just its id', function (Closure $pair): void {
    [$store, $reader] = $pair();
    $receipt = lookupReceipt('receipt-hydrated', approvalContext: ['tenant_id' => 7, 'conversation_id' => 'c-1']);
    $store->issue($receipt);
    $store->approve($receipt->id, $receipt->toolCallId, 'user:42', new DateTimeImmutable('2026-08-01 12:03:00', new DateTimeZone('UTC')));

    // The tool-call path is a second hydration route, so a Single result carrying the right id
    // and wrong fields — a hard-coded Pending, a dropped reason — would otherwise pass. Decided
    // first, so the status cannot be right by accident.
    $view = $reader->statusForToolCall('call-lookup')->status;

    expect($view)->toBeInstanceOf(ApprovalStatusView::class)
        ->and($view?->receiptId)->toBe('receipt-hydrated')
        ->and($view?->toolCallId)->toBe('call-lookup')
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
        // The one view field a fixture that leaves it null would never catch being dropped.
        ->and($view?->approvalContext)->toBe(['tenant_id' => 7, 'conversation_id' => 'c-1']);
})->with('lookup pairs');

it('still reads each colliding receipt by its own id', function (Closure $pair): void {
    [$store, $reader] = $pair();
    $store->issue(lookupReceipt('receipt-one'));
    $store->issue(lookupReceipt('receipt-two', capability: 'orders.refund'));

    // Multiplicity is a property of the tool-call read alone. Addressing a receipt by id was
    // never ambiguous and must stay unaffected — that is the escape hatch the queue uses next.
    expect($reader->statusFor('receipt-one')?->receiptId)->toBe('receipt-one')
        ->and($reader->statusFor('receipt-two')?->receiptId)->toBe('receipt-two')
        ->and($store->find('receipt-one')?->capability)->toBe('orders.cancel')
        ->and($store->find('receipt-two')?->capability)->toBe('orders.refund');
})->with('lookup pairs');

it('propagates a custom store\'s multiplicity through the store-backed reader', function (): void {
    // The store-backed reader is what every custom store gets by default, and it is the seam an
    // implementation is most likely to regress by re-deriving the outcome instead of carrying the
    // store's. This store shares no code with the shipped ones.
    $store = new class implements ApprovalReceiptStore
    {
        /** @var list<string> */
        public array $findCalls = [];

        public function issue(ApprovalReceipt $receipt): ApprovalTransition
        {
            throw new RuntimeException('not exercised');
        }

        public function findForToolCall(string $toolCallId): ApprovalReceiptLookup
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

    $reader = new StoreBackedApprovalStatusReader($store);

    expect($reader->statusForToolCall('call-collided')->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($reader->statusForToolCall('call-collided')->receiptIds)->toBe(['r-1', 'r-2', 'r-3'])
        ->and($reader->statusForToolCall('call-collided')->count())->toBe(3)
        // Ids without views: the reader cannot hydrate what the store would not name a single
        // receipt for. A null status alone would not prove that — a reader could call find() on
        // each id, discard the results, and still look correct — so the calls are counted.
        ->and($reader->statusForToolCall('call-collided')->status)->toBeNull()
        ->and($reader->statusForToolCall('call-quiet')->outcome)->toBe(ApprovalLookupOutcome::Absent)
        ->and($store->findCalls)->toBe([]);
});

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
    $receipt = lookupReceipt('receipt-built');
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

it('issues a challenge only for a single receipt, never for absence or a collision', function (): void {
    $store = app(ApprovalReceiptStore::class);
    $manager = app(ApprovalManager::class);

    expect($manager->challengeForToolCall('call-lookup'))->toBeNull();

    $store->issue(lookupReceipt('receipt-one'));
    expect($manager->challengeForToolCall('call-lookup')?->receiptId)->toBe('receipt-one');

    // The manager must not resolve a collision by picking one — a challenge names exactly one
    // receipt, and choosing for the approver is the silent behaviour #425 exists to remove.
    $store->issue(lookupReceipt('receipt-two', capability: 'orders.refund'));
    expect($manager->challengeForToolCall('call-lookup'))->toBeNull();
});

it('issues no challenge when the proposal changed under an open receipt', function (): void {
    $store = app(ApprovalReceiptStore::class);
    $manager = app(ApprovalManager::class);

    $store->issue(lookupReceipt('receipt-open', toolCallId: 'call-changed', bindingFingerprint: str_repeat('a', 64)));
    expect($manager->challengeForToolCall('call-changed')?->receiptId)->toBe('receipt-open');

    // Same capability, new binding: the approver would otherwise be shown one of two live
    // proposals for the same call with nothing saying the other exists.
    $store->issue(lookupReceipt('receipt-revised', toolCallId: 'call-changed', bindingFingerprint: str_repeat('b', 64)));

    expect($manager->challengeForToolCall('call-changed'))->toBeNull();
});
