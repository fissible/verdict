<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalOperation;
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

// ADR 0038 §4/§5 — recordApprovalOperation() is first-class on EvidenceWriter, so every recorder handles the
// operational-event stream: the default Null recorder drops it (opt-in durability), the InMemory recorder keeps
// it for inspection, the Database recorder persists it to its own configured table, and the Attest recorder
// appends it to the tamper-evident chain. Raw ids and raw summary content never appear — only fingerprints.

function recordingOperation(
    ApprovalLane $lane = ApprovalLane::Confirmation,
    ApprovalOperation $operation = ApprovalOperation::Issued,
    ?string $summaryFingerprint = null,
): ApprovalOperationEvidence {
    return new ApprovalOperationEvidence(
        lane: $lane,
        operation: $operation,
        capability: 'orders.cancel',
        identityFingerprint: str_repeat('a', 64),
        summaryFingerprint: $summaryFingerprint,
        occurredAt: new DateTimeImmutable('2026-08-31 12:00:00'),
        invocationId: 'inv-1',
    );
}

// ── the default recorder drops it (durability is opt-in) ───────────────────────────────────────────────

it('the null recorder accepts an operational event without error and records nothing', function (): void {
    (new NullEvidenceRecorder)->recordApprovalOperation(recordingOperation());
})->throwsNoExceptions();

it('the in-memory recorder retains operational events in order', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    $recorder->recordApprovalOperation(recordingOperation(operation: ApprovalOperation::Issued));
    $recorder->recordApprovalOperation(recordingOperation(operation: ApprovalOperation::Approved));

    expect($recorder->operations())->toHaveCount(2)
        ->and($recorder->operations()[0]->operation)->toBe(ApprovalOperation::Issued)
        ->and($recorder->operations()[1]->operation)->toBe(ApprovalOperation::Approved);
});

// ── the database recorder persists to its own table and reads back the exact fingerprints ──────────────

it('the database recorder persists an operational event to the operations table', function (): void {
    EvidenceTableSchema::createOperations();
    $recorder = new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());

    $recorder->recordApprovalOperation(recordingOperation(
        lane: ApprovalLane::Review,
        operation: ApprovalOperation::Consumed,
        summaryFingerprint: str_repeat('b', 64),
    ));

    $row = app(DatabaseManager::class)->connection()->table(verdictTable('operations'))->first();
    EvidenceTableSchema::dropOperations();

    expect($row)->not->toBeNull()
        ->and($row->lane)->toBe('review')
        ->and($row->operation)->toBe('consumed')
        ->and($row->capability)->toBe('orders.cancel')
        ->and($row->identity_fingerprint)->toBe(str_repeat('a', 64))
        ->and($row->summary_fingerprint)->toBe(str_repeat('b', 64))
        ->and($row->invocation_id)->toBe('inv-1')
        ->and((string) $row->occurred_at)->toContain('2026-08-31 12:00:00'); // the operation instant is persisted
});

it('the database recorder honours a configured (non-default) operations table name', function (): void {
    // Proves the $operationsTable constructor seam: renaming the table via config routes writes there and NOT
    // to the default name (#290 config-renamable tables).
    config()->set('verdict.evidence.operations_table', 'renamed_approval_operations');
    EvidenceTableSchema::createOperations(); // drops+creates verdictTable('operations') == 'renamed_approval_operations'
    $recorder = new DatabaseEvidenceRecorder(
        app(DatabaseManager::class)->connection(),
        operationsTable: 'renamed_approval_operations',
    );

    $recorder->recordApprovalOperation(recordingOperation());

    $connection = app(DatabaseManager::class)->connection();
    $count = $connection->table('renamed_approval_operations')->count();
    $defaultExists = $connection->getSchemaBuilder()->hasTable('verdict_approval_operations');
    EvidenceTableSchema::dropOperations();

    expect($count)->toBe(1)
        ->and($defaultExists)->toBeFalse(); // nothing was written to the default table
});

it('the database recorder persists a null summary fingerprint for an unreleased summary', function (): void {
    EvidenceTableSchema::createOperations();
    $recorder = new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());

    $recorder->recordApprovalOperation(recordingOperation(summaryFingerprint: null));

    $row = app(DatabaseManager::class)->connection()->table(verdictTable('operations'))->first();
    EvidenceTableSchema::dropOperations();

    expect($row->summary_fingerprint)->toBeNull();
});

// ── the attest recorder appends the operational event to the tamper-evident chain ──────────────────────

it('the attest recorder appends an operational event to the chain, keyed on the identity anchor', function (): void {
    $store = AttestFixture::store();
    $recorder = new AttestEvidenceRecorder(
        attest: AttestFixture::registry($store),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        baseDelayMs: 1,
    );

    $operation = recordingOperation(
        operation: ApprovalOperation::Approved,
        summaryFingerprint: str_repeat('b', 64),
    );
    $recorder->recordApprovalOperation($operation);

    $tail = $store->tail('verdict');
    expect($tail)->not->toBeNull()
        ->and($tail->envelope->type)->toBe('verdict.approval_operation')
        // The identity anchor correlates the operational events for one receipt/request in the chain.
        ->and($tail->envelope->correlation)->toBe(str_repeat('a', 64))
        // The chained payload is EXACTLY the value's serialisation — no missing fields, no extras, no raw content.
        ->and($tail->envelope->payload)->toBe($operation->toArray());
});
