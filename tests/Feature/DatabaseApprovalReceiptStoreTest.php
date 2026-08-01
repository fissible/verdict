<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_approval_receipts');
    $schema->create('verdict_approval_receipts', function (Blueprint $table): void {
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
        $table->timestamps();
        $table->unique(['tool_call_id', 'capability', 'binding_fingerprint'], 'verdict_approval_receipts_binding_unique');
    });
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists('verdict_approval_receipts');
});

function databaseReceipt(
    string $fingerprint = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $id = 'rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr',
): ApprovalReceipt {
    $now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    return new ApprovalReceipt(
        id: $id,
        toolCallId: 'call-database-receipt',
        capability: 'orders.cancel',
        bindingFingerprint: $fingerprint,
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
        ->and($store->findForToolCall($receipt->toolCallId)?->status)
        ->toBe(ApprovalReceiptStatus::Approved)
        ->and($store->consume($receipt->toolCallId, $receipt->bindingFingerprint, $now)->outcome)
        ->toBe(ApprovalOutcome::Consumed)
        ->and($store->consume($receipt->toolCallId, $receipt->bindingFingerprint, $now)->outcome)
        ->toBe(ApprovalOutcome::InvalidState)
        ->and($store->findForToolCall($receipt->toolCallId)?->status)
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
        ->and($store->findForToolCall($receipt->toolCallId))->toBeNull()
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
        ->and($store->findForToolCall($receipt->toolCallId)?->status)
        ->toBe(ApprovalReceiptStatus::Rejected);
});

it('hydrates receipt timestamps as UTC regardless of the application timezone', function (): void {
    $previousTimezone = date_default_timezone_get();

    try {
        date_default_timezone_set('America/Los_Angeles');

        $store = databaseReceiptStore();
        $receipt = databaseReceipt();

        $store->issue($receipt);

        $stored = $store->findForToolCall($receipt->toolCallId);

        expect($stored)->not->toBeNull()
            ->and($stored?->expiresAt->getTimestamp())->toBe($receipt->expiresAt->getTimestamp())
            ->and($stored?->expiresAt->getTimezone()->getName())->toBe('UTC');
    } finally {
        date_default_timezone_set($previousTimezone);
    }
});

it('rejects receipt mutations inside an outer transaction on the store connection', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $store = new DatabaseApprovalReceiptStore($connection);

    $connection->beginTransaction();

    try {
        expect(fn () => $store->issue(databaseReceipt()))
            ->toThrow(UnsafeOuterTransaction::class, 'issue an approval receipt');
    } finally {
        $connection->rollBack();
    }

    expect($connection->transactionLevel())->toBe(0)
        ->and($connection->table('verdict_approval_receipts')->count())->toBe(0);
});
