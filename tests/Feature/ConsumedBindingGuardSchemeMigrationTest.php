<?php

declare(strict_types=1);

use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

/**
 * Slice 9 of ADR 0039 (keyed digest opt-in, the schema): keyed mode persists `algorithm` and
 * `key_version` alongside each consumed-binding guard (both null under the keyless default). These
 * columns arrive in a NEW add_* migration, never the frozen create migration (#466), are nullable so
 * the existing keyless writer is untouched, and the digest column stays fixed-width. No issue()/
 * consume() wiring here (later slices). Self-contained: only Pest.php globals.
 */
const CBG_TABLE = 'verdict_consumed_binding_guards';

function cbgConnection(): ConnectionInterface
{
    return app(DatabaseManager::class)->connection();
}

function cbgCreateStub(): object
{
    return require __DIR__.'/../../database/migrations/create_verdict_consumed_binding_guards_table.php.stub';
}

function cbgSchemeStub(): object
{
    return require __DIR__.'/../../database/migrations/add_scheme_to_verdict_consumed_binding_guards_table.php.stub';
}

beforeEach(function (): void {
    cbgConnection()->getSchemaBuilder()->dropIfExists(CBG_TABLE);
    cbgCreateStub()->up();
});

afterEach(function (): void {
    cbgConnection()->getSchemaBuilder()->dropIfExists(CBG_TABLE);
});

it('adds nullable algorithm and key_version columns, leaving the digest and consumed_at in place', function (): void {
    $schema = cbgConnection()->getSchemaBuilder();

    expect($schema->hasColumn(CBG_TABLE, 'algorithm'))->toBeFalse()
        ->and($schema->hasColumn(CBG_TABLE, 'key_version'))->toBeFalse();

    cbgSchemeStub()->up();

    expect($schema->hasColumns(CBG_TABLE, ['digest', 'consumed_at', 'algorithm', 'key_version']))->toBeTrue();
});

it('leaves the existing keyless writer untouched: a row without a scheme reads back null/null', function (): void {
    cbgSchemeStub()->up();

    // The keyless insert path (the shipped store) never names algorithm/key_version. The columns
    // must be nullable, or that insert would fail the moment this migration ran.
    cbgConnection()->table(CBG_TABLE)->insert([
        'digest' => str_repeat('a', 32),
        'consumed_at' => '2026-09-01 12:00:00',
    ]);

    $row = cbgConnection()->table(CBG_TABLE)->first();

    expect($row->algorithm)->toBeNull()
        ->and($row->key_version)->toBeNull();
});

it('persists a keyed guard row and reads the scheme back', function (): void {
    cbgSchemeStub()->up();

    cbgConnection()->table(CBG_TABLE)->insert([
        'digest' => str_repeat('b', 32),
        'consumed_at' => '2026-09-01 12:00:00',
        'algorithm' => 'hmac-sha256',
        'key_version' => 'v1',
    ]);

    $row = cbgConnection()->table(CBG_TABLE)->first();

    expect($row->algorithm)->toBe('hmac-sha256')
        ->and($row->key_version)->toBe('v1');
});

it('drops only the scheme columns on rollback, keeping the guard table and its rows', function (): void {
    cbgSchemeStub()->up();

    cbgConnection()->table(CBG_TABLE)->insert([
        'digest' => str_repeat('c', 32),
        'consumed_at' => '2026-09-01 12:00:00',
        'algorithm' => 'hmac-sha256',
        'key_version' => 'v1',
    ]);

    cbgSchemeStub()->down();

    $schema = cbgConnection()->getSchemaBuilder();

    expect($schema->hasTable(CBG_TABLE))->toBeTrue()
        ->and($schema->hasColumn(CBG_TABLE, 'digest'))->toBeTrue()
        ->and($schema->hasColumn(CBG_TABLE, 'consumed_at'))->toBeTrue()
        ->and($schema->hasColumn(CBG_TABLE, 'algorithm'))->toBeFalse()
        ->and($schema->hasColumn(CBG_TABLE, 'key_version'))->toBeFalse()
        ->and(cbgConnection()->table(CBG_TABLE)->count())->toBe(1); // the guard row survives the rollback
});

it('tolerates the scheme columns already existing, so an install that has them can still run it', function (): void {
    cbgSchemeStub()->up();

    // Running the same migration again must not fail with a duplicate-column error (the #466
    // hardening: a published add_* migration guards each column with a presence check).
    cbgSchemeStub()->up();

    expect(cbgConnection()->getSchemaBuilder()->hasColumns(CBG_TABLE, ['algorithm', 'key_version']))->toBeTrue();
});

it('publishes the scheme migration under the approval-migrations tag', function (): void {
    $stubs = array_map(
        static fn (string $path): string => basename($path, '.php.stub'),
        array_keys(ServiceProvider::pathsToPublish(VerdictServiceProvider::class, 'verdict-approval-migrations')),
    );

    expect($stubs)->toContain('add_scheme_to_verdict_consumed_binding_guards_table');
});
