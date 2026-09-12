<?php

declare(strict_types=1);

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DerivationKind;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Illuminate\Database\DatabaseManager;

/**
 * #311 item 6 — DatabaseEvidenceRecorder::derivationsFor() ordered by `recorded_at` alone with no
 * tiebreaker, so edges recorded in the same second came back in whatever order the engine returned
 * them (rowid on SQLite, unspecified on MySQL/PostgreSQL) — audits render differently run-to-run.
 * (provenanceFor carries a (recorded_at, id) tiebreaker, which was not #311's defect. It has one of
 * its own — the id is a random UUID, so its same-second order is not reproducible from the entries,
 * and the in-memory recorder does not sort at all. That is #480, not this file's subject.)
 *
 * The contract pinned here: derivationsFor returns edges in a deterministic total order —
 * recorded_at first (chronology dominates), then parent fingerprint, then kind — identically across
 * the database and in-memory recorders, so an audit reads the same every time and on every store.
 */
beforeEach(function (): void {
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('derivations'));
    $schema->dropIfExists(verdictTable('evidence'));
});

dataset('orderingRecorders', [
    'in-memory' => [fn (): InMemoryEvidenceRecorder => new InMemoryEvidenceRecorder],
    'database' => [fn (): DatabaseEvidenceRecorder => new DatabaseEvidenceRecorder(
        app(DatabaseManager::class)->connection(),
        verdictTable('evidence'),
        verdictTable('derivations'),
    )],
]);

function orderingEdge(
    string $parent,
    DerivationKind $kind = DerivationKind::Transformed,
    string $recordedAt = '2026-08-03 12:00:00',
    ?string $child = null,
    string $zone = 'UTC',
): ProvenanceDerivation {
    return new ProvenanceDerivation(
        correlationId: 'invocation-order',
        childContentFingerprint: $child ?? str_repeat('c', 64),
        parentContentFingerprint: $parent,
        kind: $kind,
        recordedAt: new DateTimeImmutable($recordedAt, new DateTimeZone($zone)),
    );
}

$child = str_repeat('c', 64);
$pa = str_repeat('a', 64);
$pe = str_repeat('e', 64);
$pf = str_repeat('f', 64);

it('orders same-second edges deterministically by parent fingerprint, independent of insertion order', function (Closure $make) use ($child, $pa, $pe, $pf): void {
    $recorder = $make();

    // Inserted in reverse of the sorted order; the read must not echo insertion order.
    $recorder->recordDerivation(orderingEdge($pf));
    $recorder->recordDerivation(orderingEdge($pe));
    $recorder->recordDerivation(orderingEdge($pa));

    expect(edgeSequence($recorder->derivationsFor('invocation-order', $child)))
        ->toBe([$pa.'|transformed', $pe.'|transformed', $pf.'|transformed']);
})->with('orderingRecorders');

it('breaks a same-second, same-parent tie deterministically by kind', function (Closure $make) use ($child, $pa): void {
    $recorder = $make();

    // Same parent, different kind — distinct edges; kind ascending by value: summarized < transformed.
    $recorder->recordDerivation(orderingEdge($pa, DerivationKind::Transformed));
    $recorder->recordDerivation(orderingEdge($pa, DerivationKind::Summarized));

    $kinds = array_map(
        static fn (ProvenanceDerivation $d): DerivationKind => $d->kind,
        $recorder->derivationsFor('invocation-order', $child),
    );

    expect($kinds)->toBe([DerivationKind::Summarized, DerivationKind::Transformed]);
})->with('orderingRecorders');

it('orders by recorded_at first, so chronology dominates the parent tiebreaker', function (Closure $make) use ($child, $pa, $pf): void {
    $recorder = $make();

    // Parent 'f' recorded earlier than parent 'a'. Parent-sort alone would put 'a' first; chronology
    // must win, returning 'f' (12:00:00) before 'a' (12:00:01).
    $recorder->recordDerivation(orderingEdge($pa, recordedAt: '2026-08-03 12:00:01'));
    $recorder->recordDerivation(orderingEdge($pf, recordedAt: '2026-08-03 12:00:00'));

    expect(edgeSequence($recorder->derivationsFor('invocation-order', $child)))
        ->toBe([$pf.'|transformed', $pa.'|transformed']);
})->with('orderingRecorders');

it('returns the same order however the same set of same-second edges was inserted', function (Closure $make) use ($pa, $pe, $pf): void {
    // Two children fed the identical parent set in opposite insertion orders; both must read back
    // identically — the read order is a function of the data, not of insertion.
    $recorder = $make();
    $childA = str_repeat('1', 64);
    $childB = str_repeat('2', 64);

    foreach ([$pf, $pe, $pa] as $parent) {
        $recorder->recordDerivation(orderingEdge($parent, child: $childA));
    }
    foreach ([$pa, $pe, $pf] as $parent) {
        $recorder->recordDerivation(orderingEdge($parent, child: $childB));
    }

    expect(edgeSequence($recorder->derivationsFor('invocation-order', $childA)))
        ->toBe(edgeSequence($recorder->derivationsFor('invocation-order', $childB)));
})->with('orderingRecorders');

it('keeps provenanceFor ordered by recorded_at, unaffected by the derivations change (regression)', function (): void {
    // Chronological order only, across different seconds — all this test claims. provenanceFor's
    // own cross-recorder divergence (a random-UUID tiebreak against insertion order, at any
    // precision) is #480, deliberately not fixed here; do not read this as pinning it sound.
    $recorder = new DatabaseEvidenceRecorder(
        app(DatabaseManager::class)->connection(),
        verdictTable('evidence'),
        verdictTable('derivations'),
    );

    $early = hash('sha256', 'early');
    $late = hash('sha256', 'late');
    $entry = fn (string $fingerprint, string $at): ProvenanceEntry => new ProvenanceEntry(
        correlationId: 'invocation-order',
        source: Source::external('doc'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ToolResult,
        contentFingerprint: $fingerprint,
        componentLabel: null,
        componentFingerprint: null,
        recordedAt: new DateTimeImmutable($at, new DateTimeZone('UTC')),
    );

    // Insert out of chronological order; the read must return recorded_at order.
    $recorder->recordProvenance($entry($late, '2026-08-03 12:00:01'));
    $recorder->recordProvenance($entry($early, '2026-08-03 12:00:00'));

    $order = array_map(
        static fn (ProvenanceEntry $e): string => $e->contentFingerprint,
        $recorder->provenanceFor('invocation-order'),
    );

    expect($order)->toBe([$early, $late]);
});

// ── #424: the cross-recorder contract above is false at sub-second precision ──────────────────────

/**
 * `recorded_at` reaches the database through Laravel's query grammar, whose date format is
 * `Y-m-d H:i:s` — so microseconds are discarded in PHP, before any SQL runs, and two edges recorded
 * in the same second tie on `recorded_at` and fall through to the parent/kind tiebreakers. (The
 * column being `$table->timestamp(...)` agrees with that, but it is not what does the truncating,
 * and widening the column alone would not change the write path.) The in-memory recorder compared
 * the full microsecond `DateTimeImmutable`, so for that same input it never reached the tiebreakers
 * and could return a different order. Every fixture above uses whole seconds, which is why the
 * contract they pin passed for the wrong reason.
 *
 * These cases compare the two recorders directly rather than through the `orderingRecorders`
 * dataset. Either form can catch a divergence — asserting each recorder against one expectation
 * does establish that they agree. The direct form is used because it states the claim as identity
 * between the stores rather than as two expectations that happen to match, so a future edit cannot
 * weaken one arm's expectation without the mismatch being the visible failure.
 */
function crossRecorderEdges(callable $record, ?string $child = null): array
{
    $child ??= str_repeat('c', 64);

    $inMemory = new InMemoryEvidenceRecorder;
    $database = new DatabaseEvidenceRecorder(
        app(DatabaseManager::class)->connection(),
        verdictTable('evidence'),
        verdictTable('derivations'),
    );

    $record($inMemory, $child);
    $record($database, $child);

    return [
        'in-memory' => edgeSequence($inMemory->derivationsFor('invocation-order', $child)),
        'database' => edgeSequence($database->derivationsFor('invocation-order', $child)),
    ];
}

/**
 * Edge identity, not just the parent: two edges can share a parent and differ only in kind, and a
 * parent-only projection reports those as identical however they are ordered — which is exactly the
 * gap a comparator that kept microseconds ahead of the kind tiebreaker would hide in.
 *
 * @param  list<ProvenanceDerivation>  $derivations
 * @return list<string>
 */
function edgeSequence(array $derivations): array
{
    return array_map(
        static fn (ProvenanceDerivation $d): string => $d->parentContentFingerprint.'|'.$d->kind->value,
        $derivations,
    );
}

it('returns the same order from both recorders for edges that differ only below the stored precision', function () use ($pa, $pf): void {
    // Same second, differing microseconds, and the sub-second order is the REVERSE of the parent
    // order — the one arrangement that separates a microsecond comparison from a second-truncated
    // one. The write path cannot see the difference, so the parent tiebreaker decides: [a, f].
    $orders = crossRecorderEdges(function ($recorder, string $child) use ($pa, $pf): void {
        $recorder->recordDerivation(orderingEdge($pf, recordedAt: '2026-08-03 12:00:00.001000', child: $child));
        $recorder->recordDerivation(orderingEdge($pa, recordedAt: '2026-08-03 12:00:00.002000', child: $child));
    });

    // Identity first, because that is the contract; then the value, because two recorders agreeing
    // on a wrong order would satisfy identity alone.
    expect($orders['in-memory'])->toBe($orders['database'])
        ->and($orders['database'])->toBe([$pa.'|transformed', $pf.'|transformed']);
});

it('reaches the kind tiebreaker for same-parent edges that differ only below the stored precision', function () use ($pa): void {
    // The case the parent tiebreaker cannot stand in for. One parent, one second, two kinds, with
    // the sub-second order opposing the kind order. A comparator that merely demoted microseconds
    // below the parent — keeping them ahead of kind — satisfies every other test in this file and
    // returns [transformed, summarized] here.
    $record = fn (array $kinds) => function ($recorder, string $child) use ($pa, $kinds): void {
        foreach ($kinds as $kind => $at) {
            $recorder->recordDerivation(orderingEdge(
                $pa,
                DerivationKind::from($kind),
                recordedAt: '2026-08-03 '.$at,
                child: $child,
            ));
        }
    };

    $times = ['transformed' => '12:00:00.001000', 'summarized' => '12:00:00.002000'];

    $forward = crossRecorderEdges($record($times), str_repeat('3', 64));
    $reversed = crossRecorderEdges($record(array_reverse($times, preserve_keys: true)), str_repeat('4', 64));

    expect($forward['in-memory'])->toBe($forward['database'])
        ->and($reversed['in-memory'])->toBe($reversed['database'])
        ->and($forward['database'])->toBe($reversed['database'])
        ->and($forward['database'])->toBe([$pa.'|summarized', $pa.'|transformed']);
});

it('keeps the parent tiebreaker ahead of the kind tiebreaker for same-second edges', function () use ($pa, $pf): void {
    // The mirror of the test above, and the second cheat it closes. Every other fixture here varies
    // parent with one kind, or kind with one parent, so a comparator that ordered by
    // (second, kind, parent) instead of (second, parent, kind) satisfies all of them. Here kind
    // order opposes parent order: 'summarized' sorts before 'transformed', but 'a' sorts before 'f',
    // and the parent must win.
    $record = fn (array $edges) => function ($recorder, string $child) use ($edges): void {
        foreach ($edges as [$parent, $kind, $at]) {
            $recorder->recordDerivation(orderingEdge($parent, $kind, recordedAt: $at, child: $child));
        }
    };

    $edges = [
        [$pa, DerivationKind::Transformed, '2026-08-03 12:00:00.002000'],
        [$pf, DerivationKind::Summarized, '2026-08-03 12:00:00.001000'],
    ];

    $forward = crossRecorderEdges($record($edges), str_repeat('5', 64));
    $reversed = crossRecorderEdges($record(array_reverse($edges)), str_repeat('6', 64));

    expect($forward['in-memory'])->toBe($forward['database'])
        ->and($reversed['in-memory'])->toBe($reversed['database'])
        ->and($forward['database'])->toBe($reversed['database'])
        ->and($forward['database'])->toBe([$pa.'|transformed', $pf.'|summarized']);
});

it('keeps chronology dominant across a second boundary, so the fix truncates to seconds and no further', function () use ($pa, $pf): void {
    // 200ms apart, but in adjacent seconds: the write path stores 12:00:00 and 12:00:01 and orders
    // chronologically, putting 'f' first against the parent tiebreaker. An in-memory comparator
    // truncated to the minute — or dropping recorded_at from the comparison altogether — would tie
    // here and return [a, f].
    $orders = crossRecorderEdges(function ($recorder, string $child) use ($pa, $pf): void {
        $recorder->recordDerivation(orderingEdge($pa, recordedAt: '2026-08-03 12:00:01.100000', child: $child));
        $recorder->recordDerivation(orderingEdge($pf, recordedAt: '2026-08-03 12:00:00.900000', child: $child));
    });

    expect($orders['in-memory'])->toBe($orders['database'])
        ->and($orders['database'])->toBe([$pf.'|transformed', $pa.'|transformed']);
});

it('compares parent fingerprints as text, so two numeric-looking hashes cannot tie', function (): void {
    // PHP compares two numeric strings NUMERICALLY, and a sha256 hex digest can be numeric: both of
    // these are valid 64-character hex fingerprints, both parse as 0 in exponential notation, and
    // `<=>` therefore calls them equal. SQL compares the same two lexically and does not.
    //
    // So the array-`<=>` comparator has a second way to tie where the database does not, and it
    // outlives the precision fix — whichever instant comparison replaces the timestamp key, a tie
    // here still falls through to insertion order in memory and to the parent in the database. The
    // parent and kind keys have to be compared as text.
    $numericLow = '0e'.str_repeat('1', 62);
    $numericHigh = '0e'.str_repeat('2', 62);

    $orders = crossRecorderEdges(function ($recorder, string $child) use ($numericLow, $numericHigh): void {
        $recorder->recordDerivation(orderingEdge($numericHigh, recordedAt: '2026-08-03 12:00:00.001000', child: $child));
        $recorder->recordDerivation(orderingEdge($numericLow, recordedAt: '2026-08-03 12:00:00.002000', child: $child));
    }, str_repeat('8', 64));

    expect($orders['in-memory'])->toBe($orders['database'])
        ->and($orders['database'])->toBe([$numericLow.'|transformed', $numericHigh.'|transformed']);
});

it('orders by the wall clock the write path records, so a differing zone cannot split the recorders', function () use ($pa, $pf): void {
    // The third cheat: comparing the absolute instant (getTimestamp()) instead of the wall-clock
    // string. Those agree for every same-zone fixture and diverge the moment two edges carry
    // different zones — the database writes what `prepareBindings()` formats, `Y-m-d H:i:s` of the
    // value as given, with no conversion. Noon in Los Angeles is 19:00 UTC, so an instant
    // comparison orders [f, a] while the stored rows order [a, f].
    //
    // What the write path SHOULD do with a non-UTC instant is #335's subject, not this file's. All
    // that is pinned here is that the two recorders cannot disagree about it.
    $orders = crossRecorderEdges(function ($recorder, string $child) use ($pa, $pf): void {
        $recorder->recordDerivation(orderingEdge($pa, recordedAt: '2026-08-03 12:00:00.002000', child: $child, zone: 'America/Los_Angeles'));
        $recorder->recordDerivation(orderingEdge($pf, recordedAt: '2026-08-03 13:00:00.001000', child: $child));
    }, str_repeat('7', 64));

    expect($orders['in-memory'])->toBe($orders['database'])
        ->and($orders['database'])->toBe([$pa.'|transformed', $pf.'|transformed']);
});

it('reads the same sub-second set identically however it was inserted, in both recorders', function () use ($pa, $pe, $pf): void {
    // The data-function property at sub-second precision: insertion order must not survive into the
    // read. Three edges in one second, inserted in an order that matches neither the parent order
    // nor the sub-second order.
    $times = [$pe => '12:00:00.300000', $pa => '12:00:00.900000', $pf => '12:00:00.100000'];

    // Distinct children per arm, because the derivations table's composite primary key makes a
    // re-insert of the same edge a no-op: replayed against one child, the reversed arm would read
    // back the forward arm's rows and prove nothing about insertion order.
    $record = fn (array $parents) => function ($recorder, string $child) use ($parents, $times): void {
        foreach ($parents as $parent) {
            $recorder->recordDerivation(orderingEdge($parent, recordedAt: '2026-08-03 '.$times[$parent], child: $child));
        }
    };

    $forward = crossRecorderEdges($record(array_keys($times)), str_repeat('1', 64));
    $reversed = crossRecorderEdges($record(array_reverse(array_keys($times))), str_repeat('2', 64));

    expect($forward['in-memory'])->toBe($forward['database'])
        ->and($reversed['in-memory'])->toBe($reversed['database'])
        ->and($forward['database'])->toBe($reversed['database'])
        ->and($forward['database'])->toBe([$pa.'|transformed', $pe.'|transformed', $pf.'|transformed']);
});

it('returns every in-memory edge with the instant it was given, microseconds and zone intact', function () use ($pa): void {
    // A regression guard, green today and expected to stay green: the fix belongs in the comparison,
    // not in the data. Truncating recordedAt on write would satisfy every ordering test above while
    // quietly degrading what the in-memory recorder holds — and it is a faithful store of what it
    // was handed, which is the whole reason it stands in for the database where no database is wired.
    //
    // A named non-UTC zone, because every other fixture here is UTC: a comparator that normalised
    // timestamps to UTC as it sorted would be invisible against a UTC fixture and is caught here on
    // the first read.
    //
    // Indexed by edge identity rather than read position, so it asserts preservation and nothing
    // about order. The read is repeated because the recorder returns a filtered, separately sorted
    // array: an implementation that damaged its retained records while handing back an intact
    // snapshot would satisfy the first pass and fail the second. Damage visible in what is returned
    // is caught on the first.
    $recorder = new InMemoryEvidenceRecorder;
    $zone = 'America/Los_Angeles';

    $given = [
        $pa.'|transformed' => '2026-08-03 12:00:00.002000',
        $pa.'|summarized' => '2026-08-03 12:00:00.001000',
    ];

    foreach ($given as $identity => $at) {
        [, $kind] = explode('|', $identity);
        $recorder->recordDerivation(orderingEdge($pa, DerivationKind::from($kind), recordedAt: $at, zone: $zone));
    }

    foreach (range(1, 2) as $pass) {
        $returned = [];

        foreach ($recorder->derivationsFor('invocation-order', str_repeat('c', 64)) as $derivation) {
            $returned[$derivation->parentContentFingerprint.'|'.$derivation->kind->value]
                = $derivation->recordedAt->format('Y-m-d H:i:s.u').' '.$derivation->recordedAt->getTimezone()->getName();
        }

        ksort($returned);

        expect($returned)->toBe([
            $pa.'|summarized' => '2026-08-03 12:00:00.001000 '.$zone,
            $pa.'|transformed' => '2026-08-03 12:00:00.002000 '.$zone,
        ], "pass {$pass}");
    }
});
