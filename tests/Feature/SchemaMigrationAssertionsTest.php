<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

beforeEach(function (): void {
    if (concurrencyTestDriver() === null) {
        $this->markTestSkipped(concurrencyTestSkipReason());
    }

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    foreach ([verdictTable('rate_limits'), verdictTable('execution_claims'), verdictTable('approvals')] as $table) {
        $schema->dropIfExists($table);
    }

    foreach (
        [
            'create_verdict_rate_limit_buckets_table.php.stub',
            'create_verdict_execution_claims_table.php.stub',
            'create_verdict_approval_receipts_table.php.stub',
            'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        ] as $stub
    ) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }
});

afterEach(function (): void {
    if (concurrencyTestDriver() === null) {
        return;
    }

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    foreach ([verdictTable('rate_limits'), verdictTable('execution_claims'), verdictTable('approvals')] as $table) {
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
});
