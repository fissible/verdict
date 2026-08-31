<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalLookupOutcome;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptLookup;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\DatabaseApprovalStatusReader;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalStatusReader;
use Fissible\Verdict\Approvals\StoreBackedApprovalStatusReader;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Tests\Support\CustomStatusReaderTestStore;
use Fissible\Verdict\Tests\Support\SelfPairingStatusReaderTestStore;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

function createStatusReaderSchema(Builder $schema): void
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
    createStatusReaderSchema(app(DatabaseManager::class)->connection()->getSchemaBuilder());
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('approvals'));
});

function statusReaderReceipt(
    string $id,
    ?array $approvalContext = null,
    string $toolCallId = 'call-status-reader',
    string $capability = 'orders.cancel',
    ?DateTimeImmutable $createdAt = null,
    ?DateTimeImmutable $expiresAt = null,
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

/** @return array{ApprovalReceiptStore, ApprovalStatusReader} */
function databaseReaderPair(): array
{
    $store = new DatabaseApprovalReceiptStore(app(DatabaseManager::class)->connection());

    return [$store, new DatabaseApprovalStatusReader($store)];
}

/** @return array{ApprovalReceiptStore, ApprovalStatusReader} */
function inMemoryReaderPair(): array
{
    $store = new InMemoryApprovalReceiptStore;

    return [$store, new InMemoryApprovalStatusReader($store)];
}

dataset('paired readers', [
    'database' => [fn (): array => databaseReaderPair()],
    'in-memory' => [fn (): array => inMemoryReaderPair()],
]);

it('mirrors every receipt field onto the status view, including a decided receipt read back by id', function (Closure $pair): void {
    [$store, $reader] = $pair();

    $receipt = statusReaderReceipt('receipt-view-completeness', ['tenant_id' => 7, 'conversation_id' => 'c-1']);
    $store->issue($receipt);
    $store->approve($receipt->id, $receipt->toolCallId, 'user:42', new DateTimeImmutable('2026-08-01 12:05:00', new DateTimeZone('UTC')));

    $view = $reader->statusFor($receipt->id);

    expect($view)->toBeInstanceOf(ApprovalStatusView::class)
        ->and($view->receiptId)->toBe($receipt->id)
        ->and($view->toolCallId)->toBe($receipt->toolCallId)
        ->and($view->capability)->toBe($receipt->capability)
        ->and($view->status)->toBe(ApprovalReceiptStatus::Approved)
        ->and($view->reason)->toBe('Confirm cancellation.')
        ->and($view->expiresAt->format(DATE_ATOM))->toBe($receipt->expiresAt->format(DATE_ATOM))
        ->and($view->approvedBy)->toBe('user:42')
        ->and($view->approvedAt?->format(DATE_ATOM))->toBe('2026-08-01T12:05:00+00:00')
        ->and($view->rejectedBy)->toBeNull()
        ->and($view->rejectedAt)->toBeNull()
        ->and($view->consumedAt)->toBeNull()
        ->and($view->createdAt->format(DATE_ATOM))->toBe($receipt->createdAt->format(DATE_ATOM))
        ->and($view->approvalContext)->toBe(['tenant_id' => 7, 'conversation_id' => 'c-1']);
})->with('paired readers');

it('returns null from statusFor for an unknown receipt id', function (Closure $pair): void {
    [, $reader] = $pair();

    expect($reader->statusFor('missing'))->toBeNull();
})->with('paired readers');

it('reads status by tool call id and names a collision instead of collapsing it', function (Closure $pair): void {
    [$store, $reader] = $pair();

    $store->issue(statusReaderReceipt('receipt-tc-one', toolCallId: 'call-shared'));

    expect($reader->statusForToolCall('call-shared')->status?->receiptId)->toBe('receipt-tc-one');

    $store->issue(statusReaderReceipt('receipt-tc-two', toolCallId: 'call-shared', capability: 'orders.refund'));

    // Before #425 this read returned the same null as an unknown tool call, so a queue could not
    // tell a collision from nothing at all. Reads by receipt id were never ambiguous.
    expect($reader->statusForToolCall('call-shared')->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($reader->statusForToolCall('call-shared')->status)->toBeNull()
        ->and($reader->statusForToolCall('call-unknown')->outcome)->toBe(ApprovalLookupOutcome::Absent)
        ->and($reader->statusFor('receipt-tc-one'))->not->toBeNull();
})->with('paired readers');

it('refuses an empty enumeration scope', function (Closure $pair): void {
    [, $reader] = $pair();

    expect(fn (): array => $reader->pendingWithin([]))->toThrow(InvalidArgumentException::class);
})->with('paired readers');

it('matches scope by typed canonical value: an integer does not match its string form', function (Closure $pair): void {
    [$store, $reader] = $pair();

    $store->issue(statusReaderReceipt('receipt-typed', ['tenant_id' => 1]));

    expect($reader->pendingWithin(['tenant_id' => '1']))->toBe([])
        ->and(array_map(fn (ApprovalStatusView $v): string => $v->receiptId, $reader->pendingWithin(['tenant_id' => 1])))
        ->toBe(['receipt-typed']);
})->with('paired readers');

it('requires every scope key to be present with its value; receipts with null or empty context never enumerate', function (Closure $pair): void {
    [$store, $reader] = $pair();

    $store->issue(statusReaderReceipt('receipt-both', ['tenant_id' => 9, 'conversation_id' => 'c-9']));
    $store->issue(statusReaderReceipt('receipt-tenant-only', ['tenant_id' => 9], toolCallId: 'call-b'));
    $store->issue(statusReaderReceipt('receipt-null-context', null, toolCallId: 'call-c'));
    $store->issue(statusReaderReceipt('receipt-empty-context', [], toolCallId: 'call-d'));

    $ids = array_map(fn (ApprovalStatusView $v): string => $v->receiptId, $reader->pendingWithin(['tenant_id' => 9, 'conversation_id' => 'c-9']));

    expect($ids)->toBe(['receipt-both'])
        ->and(array_map(fn (ApprovalStatusView $v): string => $v->receiptId, $reader->pendingWithin(['tenant_id' => 9])))
        ->toBe(['receipt-both', 'receipt-tenant-only']);
})->with('paired readers');

it('enumerates only persisted Pending status, keeping a lapsed-but-undecided receipt with its deadline', function (Closure $pair): void {
    [$store, $reader] = $pair();

    $now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    $decided = statusReaderReceipt('receipt-decided', ['tenant_id' => 3]);
    $store->issue($decided);
    $store->approve($decided->id, $decided->toolCallId, 'user:42', $now->modify('+1 minute'));

    $store->issue(statusReaderReceipt('receipt-lapsed', ['tenant_id' => 3], toolCallId: 'call-lapsed', expiresAt: $now->modify('-1 minute')));

    $views = $reader->pendingWithin(['tenant_id' => 3]);

    expect(array_map(fn (ApprovalStatusView $v): string => $v->receiptId, $views))->toBe(['receipt-lapsed'])
        ->and($views[0]->status)->toBe(ApprovalReceiptStatus::Pending)
        ->and($views[0]->expiresAt < $now)->toBeTrue();
})->with('paired readers');

it('orders enumeration by createdAt ascending with receiptId as the tiebreak', function (Closure $pair): void {
    [$store, $reader] = $pair();

    $base = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    $store->issue(statusReaderReceipt('receipt-z-early', ['tenant_id' => 5], toolCallId: 'call-1', createdAt: $base));
    $store->issue(statusReaderReceipt('receipt-b-late', ['tenant_id' => 5], toolCallId: 'call-2', createdAt: $base->modify('+2 minutes')));
    $store->issue(statusReaderReceipt('receipt-a-late', ['tenant_id' => 5], toolCallId: 'call-3', createdAt: $base->modify('+2 minutes')));

    expect(array_map(fn (ApprovalStatusView $v): string => $v->receiptId, $reader->pendingWithin(['tenant_id' => 5])))
        ->toBe(['receipt-z-early', 'receipt-a-late', 'receipt-b-late']);
})->with('paired readers');

it('returns no enumeration matches from a database whose approval_context column is not migrated', function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropColumns(verdictTable('approvals'), ['approval_context']);

    [$store, $reader] = databaseReaderPair();
    $store->issue(statusReaderReceipt('receipt-unmigrated', ['tenant_id' => 1]));

    expect($reader->pendingWithin(['tenant_id' => 1]))->toBe([]);
});

it('serves the two status reads over any custom store, and refuses enumeration it cannot honestly answer', function (): void {
    $store = new class implements ApprovalReceiptStore
    {
        public ?ApprovalReceipt $receipt = null;

        public function issue(ApprovalReceipt $receipt): ApprovalTransition
        {
            $this->receipt = $receipt;

            return ApprovalTransition::to(ApprovalOutcome::Issued, $receipt);
        }

        public function findForToolCall(string $toolCallId): ApprovalReceiptLookup
        {
            return $this->receipt?->toolCallId === $toolCallId
                ? ApprovalReceiptLookup::single($this->receipt)
                : ApprovalReceiptLookup::absent();
        }

        public function find(string $receiptId): ?ApprovalReceipt
        {
            return $this->receipt?->id === $receiptId ? $this->receipt : null;
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
    $store->issue(statusReaderReceipt('receipt-custom'));

    expect($reader->statusFor('receipt-custom')?->receiptId)->toBe('receipt-custom')
        ->and($reader->statusForToolCall('call-status-reader')->status?->receiptId)->toBe('receipt-custom')
        ->and(fn (): array => $reader->pendingWithin(['tenant_id' => 1]))->toThrow(LogicException::class);
});

it('resolves the reader paired with the configured receipt store', function (): void {
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    app()->forgetInstance(ApprovalStatusReader::class);
    app()->forgetInstance(ApprovalReceiptStore::class);
    expect(app(ApprovalStatusReader::class))->toBeInstanceOf(DatabaseApprovalStatusReader::class);

    config()->set('verdict.approvals.store', InMemoryApprovalReceiptStore::class);
    app()->forgetInstance(ApprovalStatusReader::class);
    app()->forgetInstance(ApprovalReceiptStore::class);
    expect(app(ApprovalStatusReader::class))->toBeInstanceOf(InMemoryApprovalStatusReader::class);

    config()->set('verdict.approvals.store', CustomStatusReaderTestStore::class);
    app()->forgetInstance(ApprovalStatusReader::class);
    app()->forgetInstance(ApprovalReceiptStore::class);
    expect(app(ApprovalStatusReader::class))->toBeInstanceOf(StoreBackedApprovalStatusReader::class);
});

it('enumerates on the store-owned connection, not a re-resolved configured one', function (): void {
    config()->set('database.connections.verdict_reader_second', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    $second = app(DatabaseManager::class)->connection('verdict_reader_second');
    createStatusReaderSchema($second->getSchemaBuilder());

    $store = new DatabaseApprovalReceiptStore($second);
    $reader = new DatabaseApprovalStatusReader($store);
    $store->issue(statusReaderReceipt('receipt-second-conn', ['tenant_id' => 11]));

    [, $defaultReader] = databaseReaderPair();

    expect(array_map(fn (ApprovalStatusView $v): string => $v->receiptId, $reader->pendingWithin(['tenant_id' => 11])))
        ->toBe(['receipt-second-conn'])
        ->and($defaultReader->pendingWithin(['tenant_id' => 11]))->toBe([]);
});

it('honors a store that implements the status reader contract itself', function (): void {
    config()->set('verdict.approvals.store', SelfPairingStatusReaderTestStore::class);
    app()->forgetInstance(ApprovalStatusReader::class);
    app()->forgetInstance(ApprovalReceiptStore::class);

    expect(app(ApprovalStatusReader::class))->toBeInstanceOf(SelfPairingStatusReaderTestStore::class)
        ->and(app(ApprovalStatusReader::class))->toBe(app(ApprovalReceiptStore::class));
});
