<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
use Fissible\Verdict\Capabilities\Events\CapabilityConfigurationUnrecorded;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('capability_configurations'));

    (require __DIR__.'/../../database/migrations/create_verdict_capability_configurations_table.php.stub')->up();
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('capability_configurations'));
});

it('stores the declared configuration once without closures or application data', function (): void {
    $capability = Capability::usingPolicy(
        name: 'orders.refund',
        ability: 'refund',
        resolveTarget: fn (ActionEnvelope $envelope): array => ['raw' => $envelope->proposal->arguments],
    )->rateLimit(RateLimitPolicy::fixedWindow(
        name: 'per-customer',
        limit: 5,
        windowSeconds: 3600,
        keyUsing: fn (): array => ['customer_id' => 42],
        reason: 'Friendly operator-facing copy.',
    ));

    $store = new DatabaseCapabilityConfigurationStore(app(DatabaseManager::class)->connection());
    $store->record($capability->configuration());
    $store->record($capability->configuration());

    $row = app(DatabaseManager::class)->connection()
        ->table(verdictTable('capability_configurations'))
        ->where('configuration_fingerprint', $capability->configurationFingerprint())
        ->first();

    expect($row)->not->toBeNull()
        ->and(app(DatabaseManager::class)->connection()->table(verdictTable('capability_configurations'))->count())->toBe(1)
        ->and($row->capability)->toBe('orders.refund')
        // toEqualCanonicalizing, not toBe: MySQL's native JSON column type does not guarantee
        // preserving object member order on the storage/retrieval round-trip (documented MySQL
        // behavior), unlike SQLite's plain-text storage or PostgreSQL's `json` type. This is a
        // database-engine serialization detail, not a Verdict correctness issue — configuration_fingerprint
        // is computed and stored as its own column before this round-trip, so it is unaffected.
        ->and(json_decode((string) $row->configuration, true, flags: JSON_THROW_ON_ERROR))->toEqualCanonicalizing([
            'ability' => 'refund',
            'configuration_version' => null,
            'confirmation_required' => false,
            'confirmation_ttl_seconds' => null,
            'execution_claim_policy' => null,
            'execution_target_policy' => null,
            'name' => 'orders.refund',
            'rate_limit_policy' => [
                'limit' => 5,
                'name' => 'per-customer',
                'window_seconds' => 3600,
            ],
        ]);
});

it('skips recording while the configuration table has not been migrated', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $connection->getSchemaBuilder()->dropIfExists(verdictTable('capability_configurations'));

    $capability = Capability::usingPolicy(
        name: 'orders.refund',
        ability: 'refund',
        resolveTarget: fn (ActionEnvelope $envelope): array => ['raw' => $envelope->proposal->arguments],
    );

    $store = new DatabaseCapabilityConfigurationStore($connection);
    expect($store->record($capability->configuration()))->toBeFalse();

    (require __DIR__.'/../../database/migrations/create_verdict_capability_configurations_table.php.stub')->up();

    expect($store->record($capability->configuration()))->toBeTrue();

    expect($connection->table(verdictTable('capability_configurations'))->count())->toBe(1);
});

it('skips recording while the database itself is unreachable', function (): void {
    config()->set('database.connections.missing-sqlite-file', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/verdict-nonexistent-dir/missing.sqlite',
        'prefix' => '',
    ]);

    $capability = Capability::usingPolicy(
        name: 'orders.refund',
        ability: 'refund',
        resolveTarget: fn (ActionEnvelope $envelope): array => ['raw' => $envelope->proposal->arguments],
    );

    $store = new DatabaseCapabilityConfigurationStore(
        app(DatabaseManager::class)->connection('missing-sqlite-file'),
    );

    expect($store->record($capability->configuration()))->toBeFalse();
});

it('keeps throwing from hasTable so validate can distinguish unreachable from missing', function (): void {
    config()->set('database.connections.missing-sqlite-file', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/verdict-nonexistent-dir/missing.sqlite',
        'prefix' => '',
    ]);

    $store = new DatabaseCapabilityConfigurationStore(
        app(DatabaseManager::class)->connection('missing-sqlite-file'),
    );

    expect(fn (): bool => $store->hasTable())->toThrow(QueryException::class);
});

it('announces an exception-skipped recording once per store instead of failing boot', function (): void {
    config()->set('database.connections.missing-sqlite-file', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/verdict-nonexistent-dir/missing.sqlite',
        'prefix' => '',
    ]);

    Event::fake([CapabilityConfigurationUnrecorded::class]);

    $capability = Capability::usingPolicy(
        name: 'orders.refund',
        ability: 'refund',
        resolveTarget: fn (ActionEnvelope $envelope): array => ['raw' => $envelope->proposal->arguments],
    );

    $store = new DatabaseCapabilityConfigurationStore(
        app(DatabaseManager::class)->connection('missing-sqlite-file'),
        events: app('events'),
    );

    expect($store->record($capability->configuration()))->toBeFalse()
        ->and($store->record($capability->configuration()))->toBeFalse();

    Event::assertDispatchedTimes(
        CapabilityConfigurationUnrecorded::class,
        1,
    );
});

it('skips and announces when the write itself fails instead of failing boot', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $connection->getSchemaBuilder()->dropColumns(verdictTable('capability_configurations'), ['configuration']);

    Event::fake([CapabilityConfigurationUnrecorded::class]);

    $capability = Capability::usingPolicy(
        name: 'orders.refund',
        ability: 'refund',
        resolveTarget: fn (ActionEnvelope $envelope): array => ['raw' => $envelope->proposal->arguments],
    );

    $store = new DatabaseCapabilityConfigurationStore($connection, events: app('events'));

    expect($store->record($capability->configuration()))->toBeFalse();

    Event::assertDispatched(
        CapabilityConfigurationUnrecorded::class,
        fn ($event): bool => $event->capability === 'orders.refund' && $event->reason !== '',
    );
});
