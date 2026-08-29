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
 * (provenanceFor already carries a stable (recorded_at, id) tiebreaker, so it is not the defect.)
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
): ProvenanceDerivation {
    return new ProvenanceDerivation(
        correlationId: 'invocation-order',
        childContentFingerprint: $child ?? str_repeat('c', 64),
        parentContentFingerprint: $parent,
        kind: $kind,
        recordedAt: new DateTimeImmutable($recordedAt, new DateTimeZone('UTC')),
    );
}

/** @param list<ProvenanceDerivation> $derivations @return list<string> */
function parentSequence(array $derivations): array
{
    return array_map(static fn (ProvenanceDerivation $d): string => $d->parentContentFingerprint, $derivations);
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

    expect(parentSequence($recorder->derivationsFor('invocation-order', $child)))
        ->toBe([$pa, $pe, $pf]);
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

    expect(parentSequence($recorder->derivationsFor('invocation-order', $child)))
        ->toBe([$pf, $pa]);
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

    expect(parentSequence($recorder->derivationsFor('invocation-order', $childA)))
        ->toBe(parentSequence($recorder->derivationsFor('invocation-order', $childB)));
})->with('orderingRecorders');

it('keeps provenanceFor ordered by recorded_at, unaffected by the derivations change (regression)', function (): void {
    // provenanceFor already carries a stable (recorded_at, id) tiebreaker and is out of scope; this
    // pins that the derivationsFor change does not disturb its chronological order.
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
