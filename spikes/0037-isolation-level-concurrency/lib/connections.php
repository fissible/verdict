<?php

declare(strict_types=1);
use Illuminate\Database\Capsule\Manager;

/**
 * @return array<string, array{driver: string, host: string, port: int, database: string, username: string, password: string}>
 */
function spike_connections(): array
{
    return [
        'postgres' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5433,
            'database' => 'verdict_spike',
            'username' => 'verdict',
            'password' => 'verdict',
        ],
        'postgres_serializable' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5433,
            'database' => 'verdict_spike',
            'username' => 'verdict',
            'password' => 'verdict',
        ],
        'mysql_repeatable_read' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3307,
            'database' => 'verdict_spike',
            'username' => 'verdict',
            'password' => 'verdict',
        ],
        'mysql_read_committed' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3308,
            'database' => 'verdict_spike',
            'username' => 'verdict',
            'password' => 'verdict',
        ],
        'mariadb' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3309,
            'database' => 'verdict_spike',
            'username' => 'verdict',
            'password' => 'verdict',
        ],
    ];
}

/**
 * @param  array{driver: string, host: string, port: int, database: string, username: string, password: string}  $config
 */
function spike_capsule(array $config): Manager
{
    $capsule = new Manager;

    $capsule->addConnection([
        'driver' => $config['driver'],
        'host' => $config['host'],
        'port' => $config['port'],
        'database' => $config['database'],
        'username' => $config['username'],
        'password' => $config['password'],
        'charset' => $config['driver'] === 'pgsql' ? 'utf8' : 'utf8mb4',
        'prefix' => '',
    ]);

    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    return $capsule;
}
