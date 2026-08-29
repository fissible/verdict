<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DerivationKind;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * #363: #356 gave record(), recordRelease() and recordProvenance() column introspection and taught
 * verdict:validate to audit the evidence table, but left recordDerivation() as a flat insert
 * against an unaudited table — the same asymmetry #356 opened with, in miniature.
 *
 * The derivations table has no additive migrations today, so this is prospective rather than a
 * live defect: the first `add_*` derivations migration reintroduces #356's failure on a table
 * nothing checks. Doing it now costs little; doing it later means remembering.
 *
 * What this table can simulate is limited and worth stating: four of its five columns are in the
 * composite primary key, and SQLite cannot drop an indexed column, so `recorded_at` is the only
 * lag these tests can build. That is enough to pin the behaviour.
 */
function derivationRecorder(): DatabaseEvidenceRecorder
{
    return new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());
}

function derivation(): ProvenanceDerivation
{
    return new ProvenanceDerivation(
        correlationId: 'invocation-363',
        childContentFingerprint: str_repeat('c', 64),
        parentContentFingerprint: str_repeat('d', 64),
        kind: DerivationKind::Transformed,
        recordedAt: new DateTimeImmutable('2026-08-28 09:00:00', new DateTimeZone('UTC')),
    );
}

function derivationRows(): Collection
{
    return app(DatabaseManager::class)->connection()->table(verdictTable('derivations'))->get();
}

afterEach(function (): void {
    EvidenceTableSchema::dropDerivations();
    EvidenceTableSchema::drop();
});

it('writes every derivation column when the table is current', function (): void {
    EvidenceTableSchema::createDerivations();

    derivationRecorder()->recordDerivation(derivation());

    $rows = derivationRows();

    expect($rows)->toHaveCount(1);

    $row = (array) $rows->first();

    // The control: exact values, so a degradation that quietly wrote constants or dropped columns
    // the table still has would fail here rather than pass the lag case below.
    expect((string) $row['correlation_id'])->toBe('invocation-363')
        ->and((string) $row['child_content_fingerprint'])->toBe(str_repeat('c', 64))
        ->and((string) $row['parent_content_fingerprint'])->toBe(str_repeat('d', 64))
        ->and((string) $row['kind'])->toBe(DerivationKind::Transformed->value)
        ->and((string) $row['recorded_at'])->toBe('2026-08-28 09:00:00');
});

it('reads back a derivation recorded on a current table', function (): void {
    EvidenceTableSchema::createDerivations();

    $recorder = derivationRecorder();
    $recorder->recordDerivation(derivation());

    // Positive control for the reader, so the degraded case below cannot pass by the write being
    // dropped and the read finding nothing either way.
    expect($recorder->derivationsFor('invocation-363', str_repeat('c', 64)))
        ->toEqual([derivation()]);
});

/**
 * #391 amended this. It previously asserted that a derivations table missing `recorded_at` still
 * took the row minus that column — presence-keyed degradation, in the same shape #356 established
 * for evidence.
 *
 * That was wrong here, and demonstrably so rather than as a matter of taste: every one of this
 * table's five columns is read back by derivationsFor(), which filters on the correlation and the
 * child fingerprint, orders by `recorded_at`, and hydrates all five into a ProvenanceDerivation.
 * A row written without `recorded_at` is therefore not merely thinner — the query that would read
 * it fails on the missing sort column, so the write left behind a row nothing can retrieve. The
 * old test pinned exactly the defect #391 is about.
 *
 * The derivations table also has no additive migrations, so unlike evidence there is no lagging
 * install to keep running: all five columns come from its create migration and always have.
 */
it('refuses to record a derivation when a required column is absent', function (): void {
    EvidenceTableSchema::createDerivationsWithout(['recorded_at']);

    $thrown = null;

    try {
        derivationRecorder()->recordDerivation(derivation());
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull('the write must fail loudly rather than leave an unreadable row');
    assert($thrown instanceof Throwable);

    // Named, and pointing at the remedy — SQLite's own unknown-column error would satisfy the
    // first two clauses, so the third is what proves this is a deliberate check. Zero rows,
    // because a row written and then complained about is still one derivationsFor() cannot return.
    expect($thrown->getMessage())->toContain('recorded_at')
        ->and($thrown->getMessage())->toContain(verdictTable('derivations'))
        ->and($thrown->getMessage())->toContain('migration')
        ->and(derivationRows())->toHaveCount(0);
});

it('does not silently succeed when the derivations table is missing entirely', function (): void {
    EvidenceTableSchema::dropDerivations();

    // Same rule #356 settled for evidence: degradation covers a lagging table, not an absent one.
    // Filtering against an empty column list would make insert() a no-op and every derivation
    // would appear to record while nothing was written.
    expect(fn () => derivationRecorder()->recordDerivation(derivation()))
        ->toThrow(Exception::class);
});

it('inspects the derivations column list once per instance rather than once per write', function (): void {
    EvidenceTableSchema::createDerivations();

    $recorder = derivationRecorder();
    $recorder->recordDerivation(derivation());

    $introspections = 0;
    DB::listen(function ($query) use (&$introspections): void {
        if (str_contains($query->sql, 'pragma_table_') || str_contains($query->sql, 'sqlite_master')) {
            $introspections++;
        }
    });

    foreach (range(1, 3) as $index) {
        $recorder->recordDerivation(new ProvenanceDerivation(
            correlationId: 'invocation-363',
            childContentFingerprint: str_repeat((string) $index, 64),
            parentContentFingerprint: str_repeat('d', 64),
            kind: DerivationKind::Transformed,
            recordedAt: new DateTimeImmutable('2026-08-28 09:00:00', new DateTimeZone('UTC')),
        ));
    }

    // Provenance derivations are written per parent edge, so a schema query per write is a worse
    // cost here than on the decision path. Same contract as the evidence memo: inspected once per
    // instance, with process restart as the invalidation boundary.
    expect($introspections)->toBe(0)
        ->and(derivationRows())->toHaveCount(4);
});
