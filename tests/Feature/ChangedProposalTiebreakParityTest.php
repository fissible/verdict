<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\Events\ApprovalProposalChangedUnderOpenReceipt;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;

// #550 (#538 follow-up): #538 aligned the IN-MEMORY changed-proposal tiebreak to byte order (strcmp),
// but DatabaseApprovalReceiptStore::lockedOpenReceiptForChangedProposal still selects with ORDER BY id,
// which inherits the connection COLLATION. For MIXED-CASE same-second ids, byte order and collation
// disagree (uppercase < lowercase in ASCII; case-insensitive/locale collations rank them otherwise), so
// the two stores can name DIFFERENT prior receipts. The repo already solved this for the status readers
// (hydrate, then impose second-precision createdAt + byte-order id in PHP). Apply the same here.
// Meaningful only where collation != byte order, i.e. on PostgreSQL/MySQL; self-skips on SQLite (binary).

const CPT_TABLE_LOCKS = 'verdict_binding_admission_locks';

// Same createdAt; ids chosen so byte order and a case-insensitive/locale collation disagree:
// strcmp: 'aaa…'(0x61) > 'BBB…'(0x42) -> byte order picks the 'a' id.
// collation (ci/locale): 'a' < 'b'(=B) -> ORDER BY id DESC picks the 'B' id.
const CPT_ID_BYTEORDER_GREATEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const CPT_ID_BYTEORDER_LEAST = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

function cptReceipt(string $id, string $fingerprint): ApprovalReceipt
{
    return new ApprovalReceipt(
        id: $id,
        toolCallId: 'call-shared',
        capability: 'orders.cancel',
        bindingFingerprint: $fingerprint,
        provenance: null,
        approvalContext: null,
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm.',
        expiresAt: new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        updatedAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
    );
}

/** Seed two equal-timestamp open receipts, issue a changed proposal, return the prior the event names. */
function cptSelectedPrior(ApprovalReceiptStore $store, Dispatcher $events): string
{
    expect($store->issue(cptReceipt(CPT_ID_BYTEORDER_LEAST, hash('sha256', 'b1')))->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->issue(cptReceipt(CPT_ID_BYTEORDER_GREATEST, hash('sha256', 'b2')))->outcome)->toBe(ApprovalOutcome::Issued);

    $captured = [];
    $events->listen(
        ApprovalProposalChangedUnderOpenReceipt::class,
        function (ApprovalProposalChangedUnderOpenReceipt $e) use (&$captured): void {
            $captured[] = $e->openReceiptId;
        },
    );
    $store->issue(cptReceipt(hash('sha256', 'third'), hash('sha256', 'b3')));

    expect($captured)->not->toBeEmpty();

    return end($captured);
}

beforeEach(function (): void {
    if (app(DatabaseManager::class)->connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite uses binary collation (= byte order), so there is no divergence to test.');
    }
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));
    $schema->dropIfExists(CPT_TABLE_LOCKS);
    foreach ([
        'create_verdict_approval_receipts_table.php.stub',
        'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        'add_approval_context_to_verdict_approval_receipts_table.php.stub',
        'create_verdict_binding_admission_locks_table.php.stub',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));
    $schema->dropIfExists(CPT_TABLE_LOCKS);
});

it('database and in-memory stores name the SAME changed-proposal prior for mixed-case ids (byte order)', function (): void {
    $dbEvents = new Dispatcher;
    $dbPrior = cptSelectedPrior(new DatabaseApprovalReceiptStore(connection: app(DatabaseManager::class)->connection(), events: $dbEvents), $dbEvents);

    // Fixture precondition: the active column collation must actually disagree with byte order,
    // or this test is vacuous (a PostgreSQL C/POSIX or binary MySQL collation sorts by byte value,
    // under which the unfixed SQL selector would already agree with byte order). A raw ORDER BY id
    // DESC over the two seeded ids must return the byte-order-LEAST id first.
    $rawTopByCollation = app(DatabaseManager::class)->connection()->table(verdictTable('approvals'))
        ->whereIn('id', [CPT_ID_BYTEORDER_LEAST, CPT_ID_BYTEORDER_GREATEST])
        ->orderByDesc('id')
        ->value('id');
    expect($rawTopByCollation)->toBe(
        CPT_ID_BYTEORDER_LEAST,
        'fixture precondition unmet: the connection collation does not diverge from byte order, so this test cannot exercise #550',
    );

    $memEvents = new Dispatcher;
    $memPrior = cptSelectedPrior(new InMemoryApprovalReceiptStore(events: $memEvents), $memEvents);

    // The contractual tiebreak is BYTE ORDER; both stores must pick the byte-order-greatest id.
    expect($dbPrior)->toBe(CPT_ID_BYTEORDER_GREATEST)
        ->and($memPrior)->toBe(CPT_ID_BYTEORDER_GREATEST)
        ->and($dbPrior)->toBe($memPrior);
});
