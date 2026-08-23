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

    foreach (['verdict_rate_limit_buckets', 'verdict_execution_claims', 'verdict_approval_receipts'] as $table) {
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

    foreach (['verdict_rate_limit_buckets', 'verdict_execution_claims', 'verdict_approval_receipts'] as $table) {
        $schema->dropIfExists($table);
    }
});

it('creates the verdict_execution_claims table with expected columns, unique constraint, and indexes', function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    expect($schema->hasTable('verdict_execution_claims'))->toBeTrue();

    expect($schema->hasColumns('verdict_execution_claims', [
        'id', 'capability', 'policy', 'binding_fingerprint', 'status',
        'attempt_count', 'claimed_at',
    ]))->toBeTrue();

    // binding_fingerprint's unique constraint is unnamed, so Laravel generates a
    // name that isn't guaranteed identical across engines. Assert by column
    // instead of by name, per the maintainer's note.
    $indexes = $schema->getIndexes('verdict_execution_claims');
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

    expect($schema->hasTable('verdict_approval_receipts'))->toBeTrue();

    expect($schema->hasColumns('verdict_approval_receipts', [
        'id', 'tool_call_id', 'capability', 'binding_fingerprint', 'status', 'provenance', 'expires_at',
    ]))->toBeTrue();

    $indexes = $schema->getIndexes('verdict_approval_receipts');

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

    expect($schema->hasTable('verdict_rate_limit_buckets'))->toBeTrue();

    expect($schema->hasColumns('verdict_rate_limit_buckets', [
        'bucket_fingerprint', 'window_starts_at', 'reset_at', 'attempts',
    ]))->toBeTrue();

    $indexes = $schema->getIndexes('verdict_rate_limit_buckets');

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
