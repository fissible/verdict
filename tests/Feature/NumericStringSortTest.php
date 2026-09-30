<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\Events\ApprovalProposalChangedUnderOpenReceipt;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\EvaluationCase;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\ReproductionMetadata;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\TrialSuiteChanged;
use Fissible\Verdict\Evaluation\TrialSuiteIdentity;
use Fissible\Verdict\Evidence\CanonicalJson;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;

// #538 (codex retro-review 5.1/5.2/5.4): PHP compares numeric-looking strings NUMERICALLY under
// SORT_REGULAR / <=> / '>', so keys or ids that cast to the same number are treated as equal. Three
// sites carry the bug: CanonicalJson::encode()'s ksort (non-canonical fingerprints), the in-memory
// changed-proposal receipt-id tiebreak ('>' — diverges from the DB's lexical order), and
// TrialSuiteIdentity's ksort of BOTH the case map and the reproduction map (a false TrialSuiteChanged).
//
// The canonicalization contract asserted here is order-independence + round-trip faithfulness — the
// only real requirement of a canonical form — NOT a specific ascending order, so a minimal-compat fix
// (which need not reorder ordinary integer-keyed maps) is admissible alongside a blanket SORT_STRING.

const NS_ID_LO = '0e11111111111111111111111111111111111111111111111111111111111111';
const NS_ID_HI = '0e22222222222222222222222222222222222222222222222222222222222222';

function nsTime(string $at): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

function nsReceipt(string $id, string $fingerprint, string $createdAt): ApprovalReceipt
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
        expiresAt: nsTime('2027-01-01 00:00:00'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: nsTime($createdAt),
        updatedAt: nsTime($createdAt),
    );
}

/** Seed two OPEN equal-timestamp receipts (given insertion order), issue a changed proposal, return the prior the event names. */
function nsSelectedPriorId(ApprovalReceiptStore $store, Dispatcher $events, string $firstId, string $secondId): string
{
    expect($store->issue(nsReceipt($firstId, 'binding-1', '2026-08-01 12:00:00'))->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->issue(nsReceipt($secondId, 'binding-2', '2026-08-01 12:00:00'))->outcome)->toBe(ApprovalOutcome::Issued);

    $captured = [];
    $events->listen(
        ApprovalProposalChangedUnderOpenReceipt::class,
        function (ApprovalProposalChangedUnderOpenReceipt $e) use (&$captured): void {
            $captured[] = $e->openReceiptId;
        },
    );

    $store->issue(nsReceipt('0e33333333333333333333333333333333333333333333333333333333333333', 'binding-3', '2026-08-01 12:05:00'));

    expect($captured)->not->toBeEmpty();

    return end($captured);
}

function nsCase(string $id): EvaluationCase
{
    return EvaluationCase::attack(
        id: $id,
        version: '1',
        input: new CaseInput([], []),
        runner: fn (CaseInput $i): Observation => new Observation(Disposition::Deny, false),
        assertions: [Assertions::executed()], // a case requires >= 1 assertion; identity never runs it
    );
}

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));

    foreach ([
        'create_verdict_approval_receipts_table.php.stub',
        'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        'add_approval_context_to_verdict_approval_receipts_table.php.stub',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }

    // The database store's issue() acquires the binding admission lock (SQLite writes this table).
    verdictInstallBindingAdmissionLockTable($schema);
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));
    $schema->dropIfExists('verdict_binding_admission_locks');
});

// --- 5.1 CanonicalJson: order-independence + faithfulness ------------------------------------------

it('canonicalizes numeric-equal string keys independent of insertion order, preserving each mapping', function (): void {
    // Opposing values so a value-sorting or constant canonicalizer would be exposed by the round-trip.
    $forward = CanonicalJson::encode(['0e1' => 'Z', '0e2' => 'A'], 'probe');
    $reverse = CanonicalJson::encode(['0e2' => 'A', '0e1' => 'Z'], 'probe');

    expect($forward)->toBe($reverse); // order-independent
    expect(json_decode($forward, true))->toEqual(['0e1' => 'Z', '0e2' => 'A']); // faithful: keys map to their values
});

it('canonicalizes NESTED numeric-equal string keys independent of insertion order', function (): void {
    // A fix applied only at the top level would leave this red.
    $forward = CanonicalJson::encode(['wrap' => ['0e1' => 'Z', '0e2' => 'A']], 'probe');
    $reverse = CanonicalJson::encode(['wrap' => ['0e2' => 'A', '0e1' => 'Z']], 'probe');

    expect($forward)->toBe($reverse)
        ->and(json_decode($forward, true))->toEqual(['wrap' => ['0e1' => 'Z', '0e2' => 'A']]);
});

it('control: distinct numeric-valued keys already canonicalize order-independently', function (): void {
    // These keys are distinct numbers (no numeric tie), so SORT_REGULAR is already deterministic —
    // a passing control proving the fix need not disturb ordinary numeric-keyed maps, not a bug.
    $a = CanonicalJson::encode(['2' => 'x', '10' => 'y', '1' => 'z'], 'probe');
    $b = CanonicalJson::encode(['10' => 'y', '1' => 'z', '2' => 'x'], 'probe');

    expect($a)->toBe($b)
        ->and(json_decode($a, true))->toEqual(['2' => 'x', '10' => 'y', '1' => 'z']);
});

// --- 5.2 in-memory receipt-id tiebreak matches the database (lexical) ------------------------------

it('the in-memory store breaks a same-timestamp changed-proposal tie by lexical id, like the database', function (): void {
    $events = new Dispatcher;
    $store = new InMemoryApprovalReceiptStore(events: $events);

    // LO inserted first, so a numeric '>' tie would wrongly keep LO; the DB's orderByDesc(id) keeps HI.
    expect(nsSelectedPriorId($store, $events, NS_ID_LO, NS_ID_HI))->toBe(NS_ID_HI);
});

it('the in-memory tiebreak is insertion-order-independent (HI first also selects HI)', function (): void {
    $events = new Dispatcher;
    $store = new InMemoryApprovalReceiptStore(events: $events);

    expect(nsSelectedPriorId($store, $events, NS_ID_HI, NS_ID_LO))->toBe(NS_ID_HI);
});

it('the in-memory and database stores name the SAME changed-proposal prior', function (): void {
    $memEvents = new Dispatcher;
    $mem = new InMemoryApprovalReceiptStore(events: $memEvents);
    $memPrior = nsSelectedPriorId($mem, $memEvents, NS_ID_LO, NS_ID_HI);

    $dbEvents = new Dispatcher;
    $db = new DatabaseApprovalReceiptStore(connection: app(DatabaseManager::class)->connection(), events: $dbEvents);
    $dbPrior = nsSelectedPriorId($db, $dbEvents, NS_ID_LO, NS_ID_HI);

    expect($memPrior)->toBe($dbPrior)
        ->and($memPrior)->toBe(NS_ID_HI);
});

// --- 5.4 TrialSuiteIdentity: both ksort sites (cases AND reproduction) -----------------------------

it('does not report the CASE set changed when numeric-looking case ids are re-declared in a different order', function (): void {
    $suiteA = new SecuritySuite('s', '1', [nsCase('0e1'), nsCase('0e2')]);
    $suiteB = new SecuritySuite('s', '1', [nsCase('0e2'), nsCase('0e1')]); // same cases, reversed order

    $identity = TrialSuiteIdentity::of($suiteA);

    expect(fn () => $identity->assertMatches($suiteB, 1))->not->toThrow(TrialSuiteChanged::class);
});

it('does not report REPRODUCTION changed when numeric-looking component keys are re-declared in a different order', function (): void {
    // Isolates the second ksort site: cases are identical and single, only reproduction order differs.
    $case = nsCase('shipped-order');
    $suiteA = new SecuritySuite('s', '1', [$case], new ReproductionMetadata(['0e1' => 'v1', '0e2' => 'v2']));
    $suiteB = new SecuritySuite('s', '1', [$case], new ReproductionMetadata(['0e2' => 'v2', '0e1' => 'v1']));

    $identity = TrialSuiteIdentity::of($suiteA);

    expect(fn () => $identity->assertMatches($suiteB, 1))->not->toThrow(TrialSuiteChanged::class);
});
