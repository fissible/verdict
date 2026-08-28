<?php

declare(strict_types=1);

use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Illuminate\Database\DatabaseManager;

/**
 * #359 (T2): this covered three of the seven shipped tables and skipped wholesale on SQLite, so
 * the default suite asserted nothing about the migrations at all — which is how three separate
 * hand-rolled evidence fixtures drifted from them unnoticed.
 *
 * The split now follows what each engine can actually answer. Column sets are portable, so those
 * assertions run everywhere, including the default SQLite run; index, unique-constraint and
 * column-type assertions need real engine introspection and stay on the MySQL/PostgreSQL matrix.
 */
beforeEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    foreach (migratedTableNames() as $table) {
        $schema->dropIfExists($table);
    }

    foreach (
        [
            'create_verdict_rate_limit_buckets_table.php.stub',
            'create_verdict_execution_claims_table.php.stub',
            'create_verdict_approval_receipts_table.php.stub',
            'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
            'add_approval_context_to_verdict_approval_receipts_table.php.stub',
            'create_verdict_action_intents_table.php.stub',
            'create_verdict_capability_configurations_table.php.stub',
        ] as $stub
    ) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }

    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();
});

/** @return list<string> */
function migratedTableNames(): array
{
    return [
        verdictTable('rate_limits'),
        verdictTable('execution_claims'),
        verdictTable('approvals'),
        verdictTable('evidence'),
        verdictTable('derivations'),
        verdictTable('intents'),
        verdictTable('capability_configurations'),
    ];
}

afterEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    foreach (migratedTableNames() as $table) {
        $schema->dropIfExists($table);
    }
});

it('creates the verdict_execution_claims table with expected columns, unique constraint, and indexes', function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    expect($schema->hasTable(verdictTable('execution_claims')))->toBeTrue();

    expect($schema->hasColumns(verdictTable('execution_claims'), [
        'id', 'capability', 'policy', 'binding_fingerprint', 'status',
        'attempt_count', 'claimed_at',
    ]))->toBeTrue();

    // binding_fingerprint's unique constraint is unnamed, so Laravel generates a
    // name that isn't guaranteed identical across engines. Assert by column
    // instead of by name, per the maintainer's note.
    $indexes = $schema->getIndexes(verdictTable('execution_claims'));
    $bindingFingerprintUnique = collect($indexes)->first(
        fn (array $index): bool => $index['unique'] === true
            && $index['columns'] === ['binding_fingerprint']
    );

    expect($bindingFingerprintUnique)->not->toBeNull();

    $statusUpdatedAtIndex = collect($indexes)->first(
        fn (array $index): bool => $index['columns'] === ['status', 'updated_at']
    );

    // This index exists defensively for potential future capability-based queries.
    // Currently no store query in src/ filters claims by capability.
    $capabilityStatusIndex = collect($indexes)->first(
        fn (array $index): bool => $index['columns'] === ['capability', 'status']
    );

    expect($statusUpdatedAtIndex)->not->toBeNull()
        ->and($capabilityStatusIndex)->not->toBeNull();

})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('creates the verdict_approval_receipts table with expected columns, unique constraint, and indexes', function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    expect($schema->hasTable(verdictTable('approvals')))->toBeTrue();

    expect($schema->hasColumns(verdictTable('approvals'), [
        'id', 'tool_call_id', 'capability', 'binding_fingerprint', 'status', 'provenance', 'expires_at',
    ]))->toBeTrue();

    $indexes = $schema->getIndexes(verdictTable('approvals'));

    // Explicitly named, so we can assert by name directly.
    $bindingUnique = collect($indexes)->first(
        fn (array $index): bool => $index['name'] === 'verdict_approval_receipts_binding_unique'
            && $index['unique'] === true
            && $index['columns'] === ['tool_call_id', 'capability', 'binding_fingerprint']
    );

    $toolCallStatusIndex = collect($indexes)->first(
        fn (array $index): bool => $index['columns'] === ['tool_call_id', 'status']
    );
    $statusExpiresIndex = collect($indexes)->first(
        fn (array $index): bool => $index['columns'] === ['status', 'expires_at']
    );

    expect($bindingUnique)->not->toBeNull()
        ->and($toolCallStatusIndex)->not->toBeNull()
        ->and($statusExpiresIndex)->not->toBeNull();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('creates the verdict_rate_limit_buckets table with expected columns, unique constraint, and index', function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    expect($schema->hasTable(verdictTable('rate_limits')))->toBeTrue();

    expect($schema->hasColumns(verdictTable('rate_limits'), [
        'bucket_fingerprint', 'window_starts_at', 'reset_at', 'attempts',
    ]))->toBeTrue();

    $indexes = $schema->getIndexes(verdictTable('rate_limits'));

    $windowUnique = collect($indexes)->first(
        fn (array $index): bool => $index['name'] === 'verdict_rate_limit_bucket_window_unique'
            && $index['unique'] === true
            && $index['columns'] === ['bucket_fingerprint', 'window_starts_at']
    );

    $resetAtIndex = collect($indexes)->first(
        fn (array $index): bool => $index['columns'] === ['reset_at']
    );

    expect($windowUnique)->not->toBeNull()
        ->and($resetAtIndex)->not->toBeNull();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('declares fingerprint columns as fixed 64-char and time columns as engine timestamps', function (): void {
    // #168's remaining half (#287 covered existence; this covers type). Fingerprints are
    // char(64) — a fixed-width hex digest, so a driver reporting varchar would mean the stub
    // drifted; time columns are engine timestamps. getColumnType() reports the driver's own
    // name for the type, so the expectation is a per-family set, not one string.
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    $fingerprints = [
        [verdictTable('rate_limits'), 'bucket_fingerprint'],
        [verdictTable('execution_claims'), 'binding_fingerprint'],
        [verdictTable('approvals'), 'binding_fingerprint'],
    ];
    $timestamps = [
        [verdictTable('rate_limits'), 'window_starts_at'],
        [verdictTable('rate_limits'), 'reset_at'],
        [verdictTable('execution_claims'), 'claimed_at'],
        [verdictTable('approvals'), 'expires_at'],
    ];

    foreach ($fingerprints as [$table, $column]) {
        // MySQL/MariaDB report `char`; PostgreSQL reports `bpchar` (blank-padded char).
        expect($schema->getColumnType($table, $column))
            ->toBeIn(['char', 'bpchar'], "{$table}.{$column} is not a fixed char column");

        $full = $schema->getColumnType($table, $column, true);
        expect(str_contains($full, '64'))->toBeTrue("{$table}.{$column} full type [{$full}] is not 64 wide");
    }

    foreach ($timestamps as [$table, $column]) {
        expect($schema->getColumnType($table, $column))
            ->toBeIn(['timestamp', 'datetime'], "{$table}.{$column} is not a timestamp column");
    }
    // Column types need real engine introspection; SQLite reports its own affinities. This
    // previously rode the file-wide skip that the column assertions above no longer want.
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('creates the four tables this file did not cover, with their expected columns', function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    // Portable on every driver, so this one runs in the default SQLite suite. Columns are the
    // drift class #359 is about: a fixture that disagrees with the migration about which columns
    // exist is exactly what went unnoticed in three evidence test fixtures.
    expect($schema->hasColumns(verdictTable('evidence'), [
        'id', 'record_type', 'correlation_id', 'invocation_id', 'intent_id', 'capability',
        'tool_kind', 'configuration_fingerprint', 'actor_fingerprint', 'subject_fingerprint',
        'target_source', 'tool_description_fingerprint', 'invocation_tool_description_fingerprint',
        'tool_description_matched', 'stage', 'disposition', 'claim_type', 'record_digest',
        'channel', 'component_label', 'component_fingerprint', 'content_fingerprint',
        'recorded_at',
    ]))->toBeTrue();

    expect($schema->hasColumns(verdictTable('derivations'), [
        'correlation_id', 'child_content_fingerprint', 'parent_content_fingerprint', 'kind',
        'recorded_at',
    ]))->toBeTrue();

    expect($schema->hasColumns(verdictTable('intents'), [
        'id', 'capability', 'configuration_fingerprint', 'actor_fingerprint', 'subject_fingerprint',
        'execution_target_identity_fingerprint', 'argument_fingerprint', 'invocation_id',
        'recorded_at',
    ]))->toBeTrue();

    expect($schema->hasColumns(verdictTable('capability_configurations'), [
        'configuration_fingerprint', 'capability', 'configuration', 'first_seen_at',
    ]))->toBeTrue();
});

it('indexes the four previously uncovered tables the way their queries read them', function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    // The scheduled-verification anti-join (#160) reads evidence by intent_id; without this index
    // it is a full scan of the evidence table on every run.
    $intentIdIndex = collect($schema->getIndexes(verdictTable('evidence')))->first(
        fn (array $index): bool => $index['columns'] === ['intent_id']
    );

    // provenanceFor() filters by record_type + correlation_id, and derivationsFor() by
    // correlation_id + child_content_fingerprint.
    $derivationLookup = collect($schema->getIndexes(verdictTable('derivations')))->first(
        fn (array $index): bool => $index['columns'][0] === 'correlation_id'
    );

    expect($intentIdIndex)->not->toBeNull()
        ->and($derivationLookup)->not->toBeNull();
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());
