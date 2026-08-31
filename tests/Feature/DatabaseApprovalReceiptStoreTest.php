<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalLookupOutcome;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Approvals\ProvenanceDisclosure;
use Fissible\Verdict\Approvals\UpstreamSource;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

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

function databaseReceipt(
    string $fingerprint = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $id = 'rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr',
    ?array $approvalContext = null,
): ApprovalReceipt {
    $now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    return new ApprovalReceipt(
        id: $id,
        toolCallId: 'call-database-receipt',
        capability: 'orders.cancel',
        bindingFingerprint: $fingerprint,
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

function databaseReceiptStore(): DatabaseApprovalReceiptStore
{
    return new DatabaseApprovalReceiptStore(app(DatabaseManager::class)->connection());
}

it('atomically advances a database receipt from pending to approved to consumed', function (): void {
    $store = databaseReceiptStore();
    $receipt = databaseReceipt();
    $now = new DateTimeImmutable('2026-08-01 12:01:00');

    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($store->approve($receipt->id, $receipt->toolCallId, 'customer:72', $now)->outcome)
        ->toBe(ApprovalOutcome::Approved)
        ->and($store->validate($receipt->toolCallId, $receipt->bindingFingerprint, $now)->outcome)
        ->toBe(ApprovalOutcome::Approved)
        ->and($store->findForToolCall($receipt->toolCallId)->receipt?->status)
        ->toBe(ApprovalReceiptStatus::Approved)
        ->and($store->consume($receipt->toolCallId, $receipt->bindingFingerprint, $now)->outcome)
        ->toBe(ApprovalOutcome::Consumed)
        ->and($store->consume($receipt->toolCallId, $receipt->bindingFingerprint, $now)->outcome)
        ->toBe(ApprovalOutcome::InvalidState)
        ->and($store->findForToolCall($receipt->toolCallId)->receipt?->status)
        ->toBe(ApprovalReceiptStatus::Consumed);
});

it('keeps colliding provider tool-call IDs separated by exact action binding', function (): void {
    $store = databaseReceiptStore();
    $receipt = databaseReceipt();
    $different = databaseReceipt(str_repeat('b', 64), str_repeat('s', 64));
    $now = new DateTimeImmutable('2026-08-01 12:01:00');

    $store->issue($receipt);
    $store->approve($receipt->id, $receipt->toolCallId, 'customer:72', $now);

    expect($store->issue($different)->outcome)->toBe(ApprovalOutcome::Issued)
        // Two receipts now share the tool-call id: the read reports multiplicity by name
        // (#425), never the absence it used to be indistinguishable from.
        ->and($store->findForToolCall($receipt->toolCallId)->outcome)->toBe(ApprovalLookupOutcome::Multiple)
        ->and($store->findForToolCall($receipt->toolCallId)->receiptIds)
        ->toEqualCanonicalizing([$receipt->id, $different->id])
        ->and($store->consume($receipt->toolCallId, $different->bindingFingerprint, $now)->outcome)
        ->toBe(ApprovalOutcome::InvalidState)
        ->and($store->consume($receipt->toolCallId, $receipt->bindingFingerprint, $now)->outcome)
        ->toBe(ApprovalOutcome::Consumed);
});

it('requires the unpredictable receipt identifier to approve or reject', function (): void {
    $store = databaseReceiptStore();
    $receipt = databaseReceipt();
    $now = new DateTimeImmutable('2026-08-01 12:01:00');

    $store->issue($receipt);

    expect($store->approve(str_repeat('x', 64), $receipt->toolCallId, 'customer:72', $now)->outcome)
        ->toBe(ApprovalOutcome::NotFound)
        ->and($store->reject(str_repeat('x', 64), $receipt->toolCallId, 'customer:72', $now)->outcome)
        ->toBe(ApprovalOutcome::NotFound)
        ->and($store->reject($receipt->id, $receipt->toolCallId, 'customer:72', $now)->outcome)
        ->toBe(ApprovalOutcome::Rejected)
        ->and($store->approve($receipt->id, $receipt->toolCallId, 'customer:72', $now)->outcome)
        ->toBe(ApprovalOutcome::InvalidState)
        ->and($store->findForToolCall($receipt->toolCallId)->receipt?->status)
        ->toBe(ApprovalReceiptStatus::Rejected);
});

it('hydrates receipt timestamps as UTC regardless of the application timezone', function (): void {
    $previousTimezone = date_default_timezone_get();

    try {
        date_default_timezone_set('America/Los_Angeles');

        $store = databaseReceiptStore();
        $receipt = databaseReceipt();

        $store->issue($receipt);

        $stored = $store->findForToolCall($receipt->toolCallId)->receipt;

        expect($stored)->not->toBeNull()
            ->and($stored?->expiresAt->getTimestamp())->toBe($receipt->expiresAt->getTimestamp())
            ->and($stored?->expiresAt->getTimezone()->getName())->toBe('UTC');
    } finally {
        date_default_timezone_set($previousTimezone);
    }
});

it('rejects every receipt mutation inside an outer transaction on the store connection', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $store = new DatabaseApprovalReceiptStore($connection);
    $receipt = databaseReceipt();
    $at = new DateTimeImmutable('2026-08-01 12:01:00');

    $store->issue($receipt);

    $mutations = [
        'issue an approval receipt' => fn () => $store->issue(databaseReceipt()),
        'approve an approval receipt' => fn () => $store->approve($receipt->id, $receipt->toolCallId, 'customer:72', $at),
        'reject an approval receipt' => fn () => $store->reject($receipt->id, $receipt->toolCallId, 'customer:72', $at),
        'consume an approval receipt' => fn () => $store->consume($receipt->toolCallId, $receipt->bindingFingerprint, $at),
    ];
    $outcomes = [];

    $connection->beginTransaction();

    try {
        // Every mutation runs, and the four results are asserted together: chaining the four
        // refusals through one expectation would stop at the first broken guard and report one
        // failure where there were four, hiding whether the others wrote durable state.
        foreach ($mutations as $operation => $mutate) {
            try {
                $mutate();

                $outcomes[$operation] = 'completed';
            } catch (Throwable $error) {
                $outcomes[$operation] = $error instanceof UnsafeOuterTransaction
                    && str_contains($error->getMessage(), $operation)
                        ? 'refused'
                        : $error::class.': '.$error->getMessage();
            }
        }
    } finally {
        $connection->rollBack();
    }

    expect($outcomes)->toBe([
        'issue an approval receipt' => 'refused',
        'approve an approval receipt' => 'refused',
        'reject an approval receipt' => 'refused',
        'consume an approval receipt' => 'refused',
    ]);

    expect($connection->transactionLevel())->toBe(0)
        ->and($connection->table(verdictTable('approvals'))->count())->toBe(1)
        ->and($store->findForToolCall($receipt->toolCallId)->receipt?->status)->toBe(ApprovalReceiptStatus::Pending);
});

it('round-trips the approver provenance payload through the durable receipt store', function (): void {
    $store = databaseReceiptStore();
    $receipt = databaseReceipt();
    $provenance = ProposalProvenance::declared(
        sources: [new UpstreamSource(
            source: Source::external('knowledge-base'),
            trust: Trust::Untrusted,
            dataClass: DataClass::Internal,
            channel: ContextChannel::RetrievedDocument,
        )],
        undescribedSourceCount: 2,
        withheldSourceCount: 1,
    );

    $store->issue(new ApprovalReceipt(
        id: $receipt->id,
        toolCallId: $receipt->toolCallId,
        capability: $receipt->capability,
        bindingFingerprint: $receipt->bindingFingerprint,
        provenance: $provenance,
        approvalContext: $receipt->approvalContext,
        status: $receipt->status,
        reason: $receipt->reason,
        expiresAt: $receipt->expiresAt,
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $receipt->createdAt,
        updatedAt: $receipt->updatedAt,
    ));

    $stored = $store->findForToolCall($receipt->toolCallId)->receipt?->provenance;

    expect($stored?->disclosure)->toBe(ProvenanceDisclosure::Declared)
        ->and($stored?->sources)->toHaveCount(1)
        ->and($stored?->sources[0]->source->identity())->toBe('external:knowledge-base')
        ->and($stored?->sources[0]->trust)->toBe(Trust::Untrusted)
        ->and($stored?->sources[0]->dataClass)->toBe(DataClass::Internal)
        ->and($stored?->sources[0]->channel)->toBe(ContextChannel::RetrievedDocument)
        ->and($stored?->undescribedSourceCount)->toBe(2)
        ->and($stored?->withheldSourceCount)->toBe(1);
});

it('reads a receipt issued before provenance was recorded as never captured', function (): void {
    $store = databaseReceiptStore();
    $store->issue(databaseReceipt());

    expect($store->findForToolCall('call-database-receipt')->receipt?->provenance)->toBeNull();
});

it('round-trips the approval context through the database', function (): void {
    $store = databaseReceiptStore();
    $receipt = databaseReceipt(approvalContext: ['tenant_id' => 'tenant-9', 'conversation_id' => 'conv-41']);

    $store->issue($receipt);

    expect($store->findForToolCall($receipt->toolCallId)->receipt?->approvalContext)
        ->toBe(['tenant_id' => 'tenant-9', 'conversation_id' => 'conv-41']);
});

it('keeps a captured-empty approval context distinct from a never-captured one', function (): void {
    $store = databaseReceiptStore();

    $store->issue(databaseReceipt(approvalContext: []));

    expect($store->findForToolCall('call-database-receipt')->receipt?->approvalContext)->toBe([]);
});

it('hydrates a receipt issued before approval context existed as never captured', function (): void {
    $store = databaseReceiptStore();

    $store->issue(databaseReceipt(approvalContext: null));

    expect($store->findForToolCall('call-database-receipt')->receipt?->approvalContext)->toBeNull();
});

it('issues and decides receipts when the approval_context column has not been migrated yet', function (): void {
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
        $table->timestamps();
    });

    $store = databaseReceiptStore();

    // Guided upgrade: composer update without the new migration must degrade to receipts whose
    // context reads as never-captured — not hard-fail every confirmation-gated issue().
    expect($store->issue(databaseReceipt(approvalContext: ['tenant_id' => 't-9']))->outcome)
        ->toBe(ApprovalOutcome::Issued)
        ->and($store->findForToolCall('call-database-receipt')->receipt?->approvalContext)->toBeNull();
});

it('hydrates a corrupt approval_context value as never captured rather than erroring', function (): void {
    $store = databaseReceiptStore();
    $receipt = databaseReceipt();

    $store->issue($receipt);
    app(DatabaseManager::class)->connection()->table(verdictTable('approvals'))
        ->where('id', $receipt->id)
        ->update(['approval_context' => '"not-an-array"']);

    expect($store->findForToolCall($receipt->toolCallId)->receipt?->approvalContext)->toBeNull();
});
