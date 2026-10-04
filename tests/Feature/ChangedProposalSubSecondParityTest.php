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

// #558 (#550/#538 follow-up): DatabaseApprovalReceiptStore::lockedOpenReceiptForChangedProposal compares a
// createdAt that was written through Laravel's query-binding date conversion (Y-m-d H:i:s — second precision;
// fractional seconds are dropped BEFORE insertion on sqlite/mysql/pgsql alike), with a byte-order id tiebreak
// (strcmp) on equal timestamps. InMemoryApprovalReceiptStore::mostRecentOpenReceiptForChangedProposal compares
// the ORIGINAL in-memory createdAt at full SUB-SECOND precision, so two receipts minted in the same whole
// second but at different microseconds order by microsecond in memory yet by the id tiebreak after a DB
// round-trip — the two stores can name DIFFERENT prior receipts. The sibling lookupForToolCall already compares
// ->format('Y-m-d H:i:s'); this method never got the same treatment. The contract this file pins: compare at
// SECOND precision (truncate, not round), break exact-second ties by byte order (strcmp, greatest wins),
// otherwise the later whole second wins. The database arm is the oracle and runs on every engine, so no
// collation divergence is required (unlike #550) and SQLite is NOT skipped.

const CPSS_TABLE_LOCKS = 'verdict_binding_admission_locks';

// Two ids whose byte order is unambiguous: 'a…' (0x61) > 'B…' (0x42) under strcmp.
const CPSS_ID_GREATEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const CPSS_ID_LEAST = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

function cpssReceipt(string $id, string $fingerprintSeed, string $createdAt): ApprovalReceipt
{
    $created = new DateTimeImmutable($createdAt, new DateTimeZone('UTC'));

    return new ApprovalReceipt(
        id: $id,
        toolCallId: 'call-shared',
        capability: 'orders.cancel',
        bindingFingerprint: hash('sha256', $fingerprintSeed), // full-width 64-char: no CHAR(64) padding trap
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
        createdAt: $created,
        updatedAt: $created,
    );
}

/**
 * Seed the two open receipts in the given insertion order, then issue a changed proposal (distinct
 * fingerprint, later timestamp). Assert the proposal is admitted and that issuing it emits EXACTLY ONE
 * ApprovalProposalChangedUnderOpenReceipt. Return the prior receipt id that event names.
 *
 * @param  array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}}  $seeds  [[id, createdAt], [id, createdAt]] in insertion order
 */
function cpssNamedPrior(ApprovalReceiptStore $store, Dispatcher $events, array $seeds): string
{
    foreach ($seeds as $i => [$id, $createdAt]) {
        expect($store->issue(cpssReceipt($id, 'seed-'.$i, $createdAt))->outcome)->toBe(ApprovalOutcome::Issued);
    }

    $captured = [];
    $events->listen(
        ApprovalProposalChangedUnderOpenReceipt::class,
        function (ApprovalProposalChangedUnderOpenReceipt $e) use (&$captured): void {
            $captured[] = $e->openReceiptId;
        },
    );

    expect($store->issue(cpssReceipt(hash('sha256', 'third'), 'proposal', '2026-09-01 12:00:05.000000'))->outcome)
        ->toBe(ApprovalOutcome::Issued);

    expect($captured)->toHaveCount(1);

    return $captured[0];
}

/**
 * Each scenario seeds two same-call open receipts and names the prior a changed proposal must reference.
 * The expected id is the ONLY one consistent with second-precision truncation + a byte-order id tiebreak;
 * every row is chosen to reject at least one plausible wrong selector.
 */
dataset('cpss_scenarios', [
    // Same whole second, different microseconds. Truncation ties -> byte-order id tiebreak picks the greatest id.
    'same second, greatest-id earlier micro (order G,L)' => [
        [[CPSS_ID_GREATEST, '2026-09-01 12:00:00.000001'], [CPSS_ID_LEAST, '2026-09-01 12:00:00.000009']],
        CPSS_ID_GREATEST, // rejects "latest full-precision timestamp" (that would pick the least id)
    ],
    'same second, greatest-id earlier micro (order L,G)' => [
        [[CPSS_ID_LEAST, '2026-09-01 12:00:00.000009'], [CPSS_ID_GREATEST, '2026-09-01 12:00:00.000001']],
        CPSS_ID_GREATEST, // rejects "retain first inserted" (that would pick the least id)
    ],
    'same second, greatest-id later micro (order G,L)' => [
        [[CPSS_ID_GREATEST, '2026-09-01 12:00:00.000009'], [CPSS_ID_LEAST, '2026-09-01 12:00:00.000001']],
        CPSS_ID_GREATEST, // rejects "earliest timestamp" (that would pick the least id)
    ],
    'same second, greatest-id later micro (order L,G)' => [
        [[CPSS_ID_LEAST, '2026-09-01 12:00:00.000001'], [CPSS_ID_GREATEST, '2026-09-01 12:00:00.000009']],
        CPSS_ID_GREATEST, // fourth assignment/order combo; still the byte-order id tiebreak
    ],
    'same second, equal nonzero microseconds (order L,G)' => [
        [[CPSS_ID_LEAST, '2026-09-01 12:00:00.500000'], [CPSS_ID_GREATEST, '2026-09-01 12:00:00.500000']],
        CPSS_ID_GREATEST, // exact tie -> byte-order id tiebreak
    ],
    // Exact integer second vs one microsecond past it. Truncation ties (both -> :00) and the id tiebreak picks
    // the greatest id; a CEILING selector (round UP to the next whole second) would push the .000001 receipt to
    // :01 and wrongly pick it. Both orders.
    'same second, zero vs one microsecond (order G,L)' => [
        [[CPSS_ID_GREATEST, '2026-09-01 12:00:00.000000'], [CPSS_ID_LEAST, '2026-09-01 12:00:00.000001']],
        CPSS_ID_GREATEST, // rejects "ceiling to next whole second" (that would pick the least id)
    ],
    'same second, zero vs one microsecond (order L,G)' => [
        [[CPSS_ID_LEAST, '2026-09-01 12:00:00.000001'], [CPSS_ID_GREATEST, '2026-09-01 12:00:00.000000']],
        CPSS_ID_GREATEST, // same, reversed insertion
    ],
    'same second, truncation not rounding (.1 vs .9)' => [
        [[CPSS_ID_GREATEST, '2026-09-01 12:00:00.100000'], [CPSS_ID_LEAST, '2026-09-01 12:00:00.900000']],
        CPSS_ID_GREATEST, // rejects "round to nearest second" (that would push .9 to 12:00:01 and pick the least id)
    ],
    // Different whole seconds: the later second wins regardless of the id tiebreak.
    'different seconds, newer has smaller id (order G,L)' => [
        [[CPSS_ID_GREATEST, '2026-09-01 12:00:00.000000'], [CPSS_ID_LEAST, '2026-09-01 12:00:02.000000']],
        CPSS_ID_LEAST, // rejects "greatest id regardless of time"
    ],
    'different seconds, newer has smaller id (order L,G)' => [
        [[CPSS_ID_LEAST, '2026-09-01 12:00:02.000000'], [CPSS_ID_GREATEST, '2026-09-01 12:00:00.000000']],
        CPSS_ID_LEAST, // rejects "greatest id regardless of time" and "first inserted"
    ],
    'different seconds, newer has greater id' => [
        [[CPSS_ID_LEAST, '2026-09-01 12:00:00.000000'], [CPSS_ID_GREATEST, '2026-09-01 12:00:02.000000']],
        CPSS_ID_GREATEST, // later whole second wins
    ],
    // Sub-second APART but straddling a calendar-second boundary (:00 vs :01): truncated-seconds says the
    // later second wins; an "elapsed distance < 1s -> tie by id" selector would wrongly tie and pick the
    // greatest id. Both orders.
    'adjacent seconds across the boundary (order G,L)' => [
        [[CPSS_ID_GREATEST, '2026-09-01 12:00:00.900000'], [CPSS_ID_LEAST, '2026-09-01 12:00:01.100000']],
        CPSS_ID_LEAST, // rejects "elapsed distance < 1s ties by id" (that would pick the greatest id)
    ],
    'adjacent seconds across the boundary (order L,G)' => [
        [[CPSS_ID_LEAST, '2026-09-01 12:00:01.100000'], [CPSS_ID_GREATEST, '2026-09-01 12:00:00.900000']],
        CPSS_ID_LEAST, // same boundary, reversed insertion
    ],
    // Across a day boundary (second + minute + hour + date all roll over at once): the comparison must use the
    // whole Y-m-d H:i:s timestamp, not an isolated lower-order field. A selector that compares only seconds
    // (or only i:s, or only H:i:s) ranks "…23:59:59" above "…00:00:00" and wrongly picks the greatest id; the
    // contract picks the later whole instant. Both orders.
    'across the day boundary (order G,L)' => [
        [[CPSS_ID_GREATEST, '2026-09-01 23:59:59.900000'], [CPSS_ID_LEAST, '2026-09-02 00:00:00.100000']],
        CPSS_ID_LEAST, // rejects "compare only a lower-order time field" (s / i:s / H:i:s)
    ],
    'across the day boundary (order L,G)' => [
        [[CPSS_ID_LEAST, '2026-09-02 00:00:00.100000'], [CPSS_ID_GREATEST, '2026-09-01 23:59:59.900000']],
        CPSS_ID_LEAST, // same boundary, reversed insertion
    ],
    // Straddles the midnight->01:00 hour where 24-hour and 12-hour clocks disagree: a 'h' (12-hour) typo in the
    // format renders 00:59:59 as "12:59:59" and ranks it above "01:00:00", picking the wrong prior. The contract
    // uses 'H' (24-hour), so the later instant 01:00:00 wins. Both orders.
    'across the midnight hour (24h vs 12h), order G,L' => [
        [[CPSS_ID_GREATEST, '2026-09-01 00:59:59.900000'], [CPSS_ID_LEAST, '2026-09-01 01:00:00.100000']],
        CPSS_ID_LEAST, // rejects a 12-hour 'h' format typo
    ],
    'across the midnight hour (24h vs 12h), order L,G' => [
        [[CPSS_ID_LEAST, '2026-09-01 01:00:00.100000'], [CPSS_ID_GREATEST, '2026-09-01 00:59:59.900000']],
        CPSS_ID_LEAST, // same boundary, reversed insertion
    ],
    // Straddles a minute boundary: an 'i'->'m' token typo (PHP minute vs month) renders the minute slot as the
    // month, so both sides collapse to the same "09" and the earlier instant's seconds win — picking the wrong
    // prior. The contract compares the real minute. Both orders.
    'across a minute boundary (minute vs month token), order G,L' => [
        [[CPSS_ID_GREATEST, '2026-09-01 12:00:59.900000'], [CPSS_ID_LEAST, '2026-09-01 12:01:00.100000']],
        CPSS_ID_LEAST, // rejects an 'i'->'m' format typo
    ],
    'across a minute boundary (minute vs month token), order L,G' => [
        [[CPSS_ID_LEAST, '2026-09-01 12:01:00.100000'], [CPSS_ID_GREATEST, '2026-09-01 12:00:59.900000']],
        CPSS_ID_LEAST, // same boundary, reversed insertion
    ],
    // Across a century boundary: the comparison must use the full 4-digit year. A 'Y'->'y' (two-digit year) typo
    // renders "99" > "00" and an omitted year drops the most-significant field entirely; both pick the earlier
    // instant. The contract's later instant (2000) wins. Both orders.
    'across the century boundary (4-digit year), order G,L' => [
        [[CPSS_ID_GREATEST, '1999-12-31 23:59:59.900000'], [CPSS_ID_LEAST, '2000-01-01 00:00:00.100000']],
        CPSS_ID_LEAST, // rejects a two-digit-year 'y' typo and an omitted-year format
    ],
    'across the century boundary (4-digit year), order L,G' => [
        [[CPSS_ID_LEAST, '2000-01-01 00:00:00.100000'], [CPSS_ID_GREATEST, '1999-12-31 23:59:59.900000']],
        CPSS_ID_LEAST, // same boundary, reversed insertion
    ],
    // Across a single->double-digit hour boundary (09 -> 10): the hour field must be zero-padded. An 'H'->'G'
    // (unpadded 24-hour) typo renders 09:59:59 as "9:59:59" and ranks it above "10:00:00" lexically, picking the
    // earlier instant. The contract's later instant (10:00:00) wins. Both orders.
    'across an unpadded-hour boundary (09 vs 10), order G,L' => [
        [[CPSS_ID_GREATEST, '2026-09-01 09:59:59.900000'], [CPSS_ID_LEAST, '2026-09-01 10:00:00.100000']],
        CPSS_ID_LEAST, // rejects an unpadded-hour 'G' format typo
    ],
    'across an unpadded-hour boundary (09 vs 10), order L,G' => [
        [[CPSS_ID_LEAST, '2026-09-01 10:00:00.100000'], [CPSS_ID_GREATEST, '2026-09-01 09:59:59.900000']],
        CPSS_ID_LEAST, // same boundary, reversed insertion
    ],
]);

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));
    $schema->dropIfExists(CPSS_TABLE_LOCKS);
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
    $schema->dropIfExists(CPSS_TABLE_LOCKS);
});

it('the database store (oracle) names the second-precision prior', function (array $seeds, string $expected): void {
    $events = new Dispatcher;
    $prior = cpssNamedPrior(
        new DatabaseApprovalReceiptStore(connection: app(DatabaseManager::class)->connection(), events: $events),
        $events,
        $seeds,
    );

    expect($prior)->toBe($expected);
})->with('cpss_scenarios');

it('the in-memory store names the SAME prior as the database store (second precision, not microsecond)', function (array $seeds, string $expected): void {
    $dbEvents = new Dispatcher;
    $dbPrior = cpssNamedPrior(
        new DatabaseApprovalReceiptStore(connection: app(DatabaseManager::class)->connection(), events: $dbEvents),
        $dbEvents,
        $seeds,
    );

    $memEvents = new Dispatcher;
    $memPrior = cpssNamedPrior(new InMemoryApprovalReceiptStore(events: $memEvents), $memEvents, $seeds);

    expect($memPrior)->toBe($expected)
        ->and($memPrior)->toBe($dbPrior);
})->with('cpss_scenarios');
