<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Reviews\DatabaseReviewStatusReader;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\InMemoryReviewStatusReader;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;
use Illuminate\Database\DatabaseManager;

// #482: the in-memory ordering comparators route their id tiebreaker through array `<=>`, which
// compares two numeric strings NUMERICALLY — so distinct ids that both parse to the same number
// (e.g. '0e' + 62 ones and '0e' + 62 twos, both == 0) tie, and the in-memory reader falls to
// insertion order while the database store orders lexically. The two shipped stores must agree.
// These fixtures construct such ids directly, seed both stores in non-lexical order, and assert the
// in-memory order matches the database order (lexical). Self-contained: only Pest.php globals.

// Two distinct, 64-char, numeric-string-equal ids (both parse to 0 via 0e-notation).
const NST_ID1 = '0e11111111111111111111111111111111111111111111111111111111111111';
const NST_ID2 = '0e22222222222222222222222222222222222222222222222222222222222222';
const NST_TC = 'call-collision';

function nstTime(string $at = '2026-09-01 12:00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

function nstReceipt(string $id, string $fingerprint): ApprovalReceipt
{
    return new ApprovalReceipt(
        id: $id, toolCallId: NST_TC, capability: 'orders.cancel', bindingFingerprint: $fingerprint,
        provenance: null, approvalContext: null, status: ApprovalReceiptStatus::Pending, reason: 'Confirm.',
        expiresAt: nstTime('2027-01-01 00:00:00'),
        approvedBy: null, approvedAt: null, rejectedBy: null, rejectedAt: null, consumedAt: null,
        createdAt: nstTime(), updatedAt: nstTime(),
    );
}

function nstReview(string $id): ReviewRequest
{
    return new ReviewRequest(
        id: $id, capability: 'orders.cancel', bindingFingerprint: str_repeat($id[3], 64),
        approvalContext: ['tenant_id' => 't'], provenance: null, approverSummary: null,
        status: ReviewStatus::Pending, reason: 'Review.',
        createdAt: nstTime(), expiresAt: nstTime('2027-01-01 00:00:00'),
        resolvedBy: null, resolvedAt: null, consumedAt: null,
    );
}

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    foreach ([verdictTable('approvals'), 'verdict_binding_admission_locks', 'verdict_review_requests'] as $t) {
        $schema->dropIfExists($t);
    }
    foreach ([
        'create_verdict_approval_receipts_table.php.stub',
        'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        'add_approval_context_to_verdict_approval_receipts_table.php.stub',
        'create_verdict_binding_admission_locks_table.php.stub',
        'create_verdict_review_requests_table.php.stub',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    foreach ([verdictTable('approvals'), 'verdict_binding_admission_locks', 'verdict_review_requests'] as $t) {
        $schema->dropIfExists($t);
    }
});

it('orders numeric-looking receipt ids lexically in lookupForToolCall, matching the database store', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $database = new DatabaseApprovalReceiptStore(connection: $connection);
    $memory = new InMemoryApprovalReceiptStore;

    // Seed in NON-lexical order (id2 first). A numeric-tie comparator keeps insertion order; a
    // lexical (strcmp) one puts id1 first regardless.
    foreach ([[NST_ID2, str_repeat('b', 64)], [NST_ID1, str_repeat('a', 64)]] as [$id, $fp]) {
        $database->issue(nstReceipt($id, $fp));
        $memory->issue(nstReceipt($id, $fp));
    }

    $databaseOrder = $database->lookupForToolCall(NST_TC)->receiptIds;
    $memoryOrder = $memory->lookupForToolCall(NST_TC)->receiptIds;

    expect($memoryOrder)->toBe($databaseOrder)
        ->and($memoryOrder)->toBe([NST_ID1, NST_ID2]); // lexical, not insertion order
});

it('orders numeric-looking review ids lexically in pendingWithin, matching the database reader', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $databaseStore = new DatabaseReviewRequestStore($connection);
    $memoryStore = new InMemoryReviewRequestStore;

    foreach ([NST_ID2, NST_ID1] as $id) { // non-lexical insertion order
        $databaseStore->issue(nstReview($id));
        $memoryStore->issue(nstReview($id));
    }

    $scope = ['tenant_id' => 't'];
    $databaseOrder = array_map(fn ($v): string => $v->requestId, (new DatabaseReviewStatusReader($databaseStore))->pendingWithin($scope));
    $memoryOrder = array_map(fn ($v): string => $v->requestId, (new InMemoryReviewStatusReader($memoryStore))->pendingWithin($scope));

    expect($memoryOrder)->toBe($databaseOrder)
        ->and($memoryOrder)->toBe([NST_ID1, NST_ID2]);
});
