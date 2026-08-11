<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';

foreach (spike_connections() as $name => $config) {
    echo "=== {$name} ===\n";

    $capsule = spike_capsule($config);
    $schema = $capsule->schema();

    // The migration stubs use Illuminate\Support\Facades\Schema/DB directly (`use Schema;` at the
    // top of each stub) — Capsule::setAsGlobal() only wires up Capsule's OWN static accessors
    // (Capsule::schema()), not the Facade base class's container-resolved accessors ('db',
    // 'db.schema') the stubs actually call. A real migration environment gets these from Laravel's
    // DatabaseServiceProvider; standalone here, bind them by hand so the stubs run unmodified.
    $container = new \Illuminate\Container\Container;
    $container->instance('db', $capsule->getDatabaseManager());
    $container->bind('db.schema', fn ($app) => $app['db']->connection()->getSchemaBuilder());
    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);

    foreach (['verdict_rate_limit_buckets', 'verdict_execution_claims', 'verdict_approval_receipts'] as $table) {
        $schema->dropIfExists($table);
    }

    foreach (
        [
            'create_verdict_rate_limit_buckets_table.php.stub',
            'create_verdict_execution_claims_table.php.stub',
            'create_verdict_approval_receipts_table.php.stub',
        ] as $stub
    ) {
        $migration = require __DIR__.'/../../database/migrations/'.$stub;
        $migration->up();
        echo "  migrated: {$stub}\n";
    }
}

echo "Bootstrap complete.\n";
