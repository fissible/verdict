<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;

/**
 * #315 — #290 made every migration stub read its TABLE name from config, but the explicitly named
 * indexes kept default-derived literal names (`verdict_evidence_actor_fingerprint_index`, etc.). On
 * PostgreSQL, index names are schema-global, so two renamed Verdict installs in one database collide
 * on the second `migrate`. Option (a): derive named index names from the configured table too.
 *
 * MySQL/PostgreSQL only — index-name introspection needs a real engine (SQLite cannot answer it).
 * Renamed table names deliberately avoid the default prefixes so a *missed* derivation (which keeps
 * a `verdict_*` name) is unmistakable.
 */
function derivedIndexSchema(): Builder
{
    return app(DatabaseManager::class)->connection()->getSchemaBuilder();
}

/** @return list<string> */
function indexNamesOf(string $table): array
{
    return array_map(static fn (array $index): string => (string) $index['name'], derivedIndexSchema()->getIndexes($table));
}

function migrateStub(string $file): object
{
    return require __DIR__.'/../../database/migrations/'.$file;
}

const RENAMED_EVIDENCE_TABLE = 'zz_evidence';
const RENAMED_APPROVALS_TABLE = 'zz_approvals';
const RENAMED_RATE_LIMITS_TABLE = 'zz_ratelimits';
const RENAMED_DERIVATIONS_TABLE = 'zz_derivations';

/** The evidence create + every add_* stub that defines a named index. */
const EVIDENCE_STUB_CHAIN = [
    'create_verdict_evidence_table.php.stub',
    'add_provenance_to_verdict_evidence_table.php.stub',
    'add_invocation_id_to_verdict_evidence_table.php.stub',
    'add_tool_kind_to_verdict_evidence_table.php.stub',
    'add_configuration_fingerprint_to_verdict_evidence_table.php.stub',
    'add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub',
    'add_target_source_to_verdict_evidence_table.php.stub',
    'add_tool_description_fingerprints_to_verdict_evidence_table.php.stub',
    'add_record_identity_to_verdict_evidence_table.php.stub',
    'add_intent_id_to_verdict_evidence_table.php.stub',
];

afterEach(function (): void {
    $schema = derivedIndexSchema();

    foreach ([RENAMED_EVIDENCE_TABLE, RENAMED_APPROVALS_TABLE, RENAMED_RATE_LIMITS_TABLE, RENAMED_DERIVATIONS_TABLE] as $table) {
        $schema->dropIfExists($table);
    }
});

it('leaves no default-named index on a renamed evidence table across the whole add_* chain (#315)', function (): void {
    config()->set('verdict.evidence.table', RENAMED_EVIDENCE_TABLE);

    foreach (EVIDENCE_STUB_CHAIN as $stub) {
        migrateStub($stub)->up();
    }

    $names = indexNamesOf(RENAMED_EVIDENCE_TABLE);

    // Not a single named index may keep the shipped `verdict_evidence_` prefix — that is the exact
    // literal that collides in a shared PostgreSQL database.
    foreach ($names as $name) {
        expect($name)->not->toStartWith('verdict_evidence_');
    }

    // And the derivation is real, not just absence.
    expect($names)->toContain(RENAMED_EVIDENCE_TABLE.'_actor_fingerprint_index');
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('drops the same derived evidence index name it created, so rollback is clean (#315)', function (): void {
    config()->set('verdict.evidence.table', RENAMED_EVIDENCE_TABLE);

    migrateStub('create_verdict_evidence_table.php.stub')->up();
    $addFingerprints = migrateStub('add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub');
    $addFingerprints->up();

    expect(indexNamesOf(RENAMED_EVIDENCE_TABLE))->toContain(RENAMED_EVIDENCE_TABLE.'_actor_fingerprint_index');

    // If down()'s dropIndex name disagrees with up()'s create name, this raises.
    $addFingerprints->down();

    expect(indexNamesOf(RENAMED_EVIDENCE_TABLE))->not->toContain(RENAMED_EVIDENCE_TABLE.'_actor_fingerprint_index');
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('derives the approvals binding-unique index from the configured table (#315)', function (): void {
    config()->set('verdict.approvals.table', RENAMED_APPROVALS_TABLE);

    migrateStub('create_verdict_approval_receipts_table.php.stub')->up();

    expect(indexNamesOf(RENAMED_APPROVALS_TABLE))
        ->toContain(RENAMED_APPROVALS_TABLE.'_binding_unique')
        ->not->toContain('verdict_approval_receipts_binding_unique');
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('derives the rate-limit window-unique index from the configured table (#315)', function (): void {
    config()->set('verdict.rate_limits.table', RENAMED_RATE_LIMITS_TABLE);

    migrateStub('create_verdict_rate_limit_buckets_table.php.stub')->up();

    expect(indexNamesOf(RENAMED_RATE_LIMITS_TABLE))
        ->toContain(RENAMED_RATE_LIMITS_TABLE.'_window_unique')
        ->not->toContain('verdict_rate_limit_bucket_window_unique');
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('derives the provenance-derivations backward index from the configured table (#315)', function (): void {
    config()->set('verdict.evidence.derivations_table', RENAMED_DERIVATIONS_TABLE);

    migrateStub('create_verdict_provenance_derivations_table.php.stub')->up();

    expect(indexNamesOf(RENAMED_DERIVATIONS_TABLE))
        ->toContain(RENAMED_DERIVATIONS_TABLE.'_backward_index')
        ->not->toContain('verdict_provenance_derivations_backward_index');
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('derives the provenance-derivations primary-key name from the configured table on PostgreSQL (#315)', function (): void {
    // MySQL/MariaDB force the primary-key name to PRIMARY, so a named primary only collides on
    // PostgreSQL — the engine #315 is actually about.
    config()->set('verdict.evidence.derivations_table', RENAMED_DERIVATIONS_TABLE);

    migrateStub('create_verdict_provenance_derivations_table.php.stub')->up();

    expect(indexNamesOf(RENAMED_DERIVATIONS_TABLE))
        ->toContain(RENAMED_DERIVATIONS_TABLE.'_primary')
        ->not->toContain('verdict_provenance_derivations_primary');
})->skip(
    fn (): bool => concurrencyTestDriver() !== 'pgsql',
    'PostgreSQL only: MySQL/MariaDB force the primary-key name to PRIMARY.',
);
