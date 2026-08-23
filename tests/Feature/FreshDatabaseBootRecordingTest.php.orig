<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Illuminate\Database\DatabaseManager;

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists('verdict_capability_configurations');
});

/**
 * The #240 ordering: artisan boots the application before dispatching any command — including
 * `migrate`, the command that would create the table boot-time recording writes to. Registration
 * must survive an unmigrated database, and the next boot after migration records what was skipped.
 */
it('boots discovered capabilities on a fresh database and records on the next boot after migrate', function (): void {
    config(['verdict.capability_configurations.store' => DatabaseCapabilityConfigurationStore::class]);
    $connection = app(DatabaseManager::class)->connection();
    $connection->getSchemaBuilder()->dropIfExists('verdict_capability_configurations');

    app()->forgetInstance(CapabilityConfigurationStore::class);
    app()->forgetInstance(CapabilityRegistry::class);

    bootDiscovery('Capabilities');

    expect(app(CapabilityRegistry::class)->has('fixtures.affirmed'))->toBeTrue()
        ->and($connection->getSchemaBuilder()->hasTable('verdict_capability_configurations'))->toBeFalse();

    (require __DIR__.'/../../database/migrations/create_verdict_capability_configurations_table.php.stub')->up();

    app()->forgetInstance(CapabilityRegistry::class);
    bootDiscovery('Capabilities');

    expect($connection->table('verdict_capability_configurations')->count())->toBeGreaterThanOrEqual(1);
});
