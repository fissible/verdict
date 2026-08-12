<?php

declare(strict_types=1);

use Fissible\Verdict\Tests\Support\ConcurrencyHarness;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;

require __DIR__.'/../vendor/autoload.php';

const BENCHMARK_REPEATS = 5;
const BENCHMARK_CONCURRENCY = [1, 5, 20];
const BENCHMARK_STORES = ['rate_limit', 'execution_claim', 'approval_receipt'];
const BENCHMARK_MODES = ['shared', 'distinct'];

$engine = $argv[1] ?? null;
$sqlitePath = sys_get_temp_dir().'/verdict-security-state-benchmark.sqlite';
$connections = [
    'sqlite' => ['driver' => 'sqlite', 'database' => $sqlitePath, 'prefix' => ''],
    'mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3307, 'database' => 'verdict_spike', 'username' => 'verdict', 'password' => 'verdict', 'charset' => 'utf8mb4', 'prefix' => ''],
];

if ($engine !== null && ! array_key_exists($engine, $connections)) {
    throw new InvalidArgumentException('Usage: php scripts/benchmark-security-state.php [sqlite|mysql]');
}

$connections = $engine === null ? $connections : [$engine => $connections[$engine]];

foreach ($connections as $connection) {
    if ($connection['driver'] === 'sqlite') {
        @unlink($connection['database']);
        touch($connection['database']);
    }
    $capsule = new Manager;
    $capsule->addConnection($connection);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $container = new Container;
    $container->instance('db', $capsule->getDatabaseManager());
    $container->bind('db.schema', fn ($app) => $app['db']->connection()->getSchemaBuilder());
    Facade::setFacadeApplication($container);
    foreach (['verdict_rate_limit_buckets', 'verdict_execution_claims', 'verdict_approval_receipts'] as $table) {
        $capsule->schema()->dropIfExists($table);
    }
    foreach (['create_verdict_rate_limit_buckets_table.php.stub', 'create_verdict_execution_claims_table.php.stub', 'create_verdict_approval_receipts_table.php.stub'] as $migration) {
        (require __DIR__.'/../database/migrations/'.$migration)->up();
    }
}

function benchmarkPercentile(array $values, float $percentile): float
{
    sort($values);

    return $values[max(0, (int) ceil($percentile * count($values)) - 1)];
}

$rows = [];
foreach ($connections as $name => $connection) {
    foreach (BENCHMARK_STORES as $store) {
        foreach (BENCHMARK_MODES as $mode) {
            foreach (BENCHMARK_CONCURRENCY as $concurrency) {
                $latencies = [];
                $errors = 0;
                $startedAt = hrtime(true);
                for ($repeat = 0; $repeat < BENCHMARK_REPEATS; $repeat++) {
                    $shared = bin2hex(random_bytes(16));
                    $payloads = array_map(fn (): array => [
                        'connection' => $connection, 'store' => $store,
                        'key' => $mode === 'shared' ? $shared : bin2hex(random_bytes(16)),
                        'at' => '2026-08-12T00:00:00+00:00',
                    ], range(1, $concurrency));
                    foreach (ConcurrencyHarness::run(__DIR__.'/benchmark-security-state-child.php', $payloads) as $result) {
                        $outcome = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
                        $latencies[] = $outcome['latency_ms'];
                        $errors += ! $outcome['ok'];
                    }
                }
                $operations = count($latencies);
                $rows[] = [
                    'engine' => $name, 'store' => $store, 'keys' => $mode, 'writers' => $concurrency,
                    'operations' => $operations, 'terminal_errors' => $errors,
                    'throughput_ops_s' => round($operations / ((hrtime(true) - $startedAt) / 1_000_000_000), 1),
                    'p50_ms' => round(benchmarkPercentile($latencies, .50), 2),
                    'p95_ms' => round(benchmarkPercentile($latencies, .95), 2),
                    'p99_ms' => round(benchmarkPercentile($latencies, .99), 2),
                ];
            }
        }
    }
}

echo json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

if (isset($connections['sqlite'])) {
    @unlink($sqlitePath);
}
