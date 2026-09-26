<?php

declare(strict_types=1);

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Illuminate\Database\DatabaseManager;

// #480: provenanceFor() diverged across recorders — the database ordered by (recorded_at, random-UUID
// id), so its same-second order was not reproducible from the entries, and the in-memory recorder did
// not sort at all (insertion order). recorded_at is stored at second precision, so same-second entries
// always reach the tiebreaker. The read order must now be a deterministic function OF THE ENTRIES,
// identical across recorders and independent of storage ids and insertion sequence. Self-contained:
// only Pest.php globals + the Tests\Support evidence schema.

const PRO_INV = 'invocation-order';

function proDbRecorder(): DatabaseEvidenceRecorder
{
    return new DatabaseEvidenceRecorder(
        app(DatabaseManager::class)->connection(),
        verdictTable('evidence'),
        verdictTable('derivations'),
    );
}

function proEntry(string $contentFingerprint, ?string $componentFingerprint = null, string $at = '2026-09-01 12:00:00'): ProvenanceEntry
{
    return new ProvenanceEntry(
        correlationId: PRO_INV,
        source: Source::external('doc'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ToolResult,
        contentFingerprint: $contentFingerprint,
        componentLabel: $componentFingerprint === null ? null : 'part',
        componentFingerprint: $componentFingerprint,
        recordedAt: new DateTimeImmutable($at, new DateTimeZone('UTC')),
    );
}

/** @return list<string> */
function proContentOrder(object $recorder): array
{
    return array_map(static fn (ProvenanceEntry $e): string => $e->contentFingerprint, $recorder->provenanceFor(PRO_INV));
}

beforeEach(function (): void {
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('derivations'));
    $schema->dropIfExists(verdictTable('evidence'));
});

it('orders same-second entries by content fingerprint, identically across recorders', function (): void {
    // Same recorded_at second, numeric-looking content fingerprints (both parse to 0), recorded in
    // NON-lexical order. The database (recorded_at, random-uuid) and the unsorted in-memory recorder
    // disagreed; both must now return the data-derived (content-fingerprint) order.
    $cfA = '0e'.str_repeat('1', 62);
    $cfB = '0e'.str_repeat('2', 62);

    $database = proDbRecorder();
    $memory = new InMemoryEvidenceRecorder;
    foreach ([$cfB, $cfA] as $cf) { // insert B before A
        $database->recordProvenance(proEntry($cf));
        $memory->recordProvenance(proEntry($cf));
    }

    expect(proContentOrder($memory))->toBe(proContentOrder($database))
        ->and(proContentOrder($memory))->toBe([$cfA, $cfB]); // lexical (strcmp), not insertion / numeric
});

it('breaks a content-fingerprint tie on the component fingerprint, identically across recorders', function (): void {
    // Two entries share a content fingerprint but differ in component fingerprint — the tiebreaker
    // must be data-derived and deterministic across recorders (not storage id / insertion).
    $cf = str_repeat('a', 64);
    $compEarly = str_repeat('1', 64);
    $compLate = str_repeat('9', 64);

    $database = proDbRecorder();
    $memory = new InMemoryEvidenceRecorder;
    foreach ([$compLate, $compEarly] as $comp) {
        $database->recordProvenance(proEntry($cf, $comp));
        $memory->recordProvenance(proEntry($cf, $comp));
    }

    $componentOrder = static fn (object $r): array => array_map(
        static fn (ProvenanceEntry $e): ?string => $e->componentFingerprint,
        $r->provenanceFor(PRO_INV),
    );

    expect($componentOrder($memory))->toBe($componentOrder($database))
        ->and($componentOrder($memory))->toBe([$compEarly, $compLate]);
});

it('reads back identically regardless of insertion sequence (order is a function of the entries)', function (): void {
    $cfA = str_repeat('a', 64);
    $cfB = str_repeat('b', 64);
    $cfC = str_repeat('c', 64);

    $forward = new InMemoryEvidenceRecorder;
    foreach ([$cfA, $cfB, $cfC] as $cf) {
        $forward->recordProvenance(proEntry($cf));
    }

    $reversed = new InMemoryEvidenceRecorder;
    foreach ([$cfC, $cfB, $cfA] as $cf) {
        $reversed->recordProvenance(proEntry($cf));
    }

    expect(proContentOrder($reversed))->toBe(proContentOrder($forward))
        ->and(proContentOrder($forward))->toBe([$cfA, $cfB, $cfC]);
});

it('still orders across different seconds by recorded_at first', function (): void {
    // recorded_at remains the primary key of the order; the data tiebreak only decides same-second ties.
    $late = str_repeat('a', 64);   // lexically first, but recorded later
    $early = str_repeat('f', 64);  // lexically after 'a', but recorded earlier

    foreach ([proDbRecorder(), new InMemoryEvidenceRecorder] as $recorder) {
        $recorder->recordProvenance(proEntry($late, at: '2026-09-01 12:00:05'));
        $recorder->recordProvenance(proEntry($early, at: '2026-09-01 12:00:00'));

        expect(proContentOrder($recorder))->toBe([$early, $late]); // chronological wins over lexical
    }
});
