<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\CapabilityDiscovery;
use Fissible\Verdict\Capabilities\CapabilityRegistrar;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Tests\AttestTestCase;
use Fissible\Verdict\Tests\TestCase;
use Fissible\Verdict\Tests\WorkbenchTestCase;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Database\DatabaseManager;

uses(TestCase::class)->in('Feature');
uses(WorkbenchTestCase::class)->in('Workbench');
uses(AttestTestCase::class)->in('Integration');

/**
 * Resolve a Verdict table name through the config key the stubs and stores read (#290), so a test
 * that creates a table by requiring a stub asserts against the same name the stub used.
 */
function verdictTable(string $area): string
{
    $map = [
        'capability_configurations' => ['verdict.capability_configurations.table', 'verdict_capability_configurations'],
        'approvals' => ['verdict.approvals.table', 'verdict_approval_receipts'],
        'evidence' => ['verdict.evidence.table', 'verdict_evidence'],
        'derivations' => ['verdict.evidence.derivations_table', 'verdict_provenance_derivations'],
        'rate_limits' => ['verdict.rate_limits.table', 'verdict_rate_limit_buckets'],
        'execution_claims' => ['verdict.execution_claims.table', 'verdict_execution_claims'],
    ];

    [$key, $default] = $map[$area] ?? throw new InvalidArgumentException("Unknown Verdict table area [{$area}].");
    $name = config($key, $default);

    return is_string($name) ? $name : $default;
}

function acceptTestSnapshot(string $name = 'test-snapshot'): ExecutionTargetPolicy
{
    return ExecutionTargetPolicy::acceptStaleSnapshot(
        name: $name,
        identityUsing: static fn (mixed $envelope, mixed $target): array => [
            'target_type' => get_debug_type($target),
            'request_local_identity' => is_object($target)
                ? spl_object_id($target)
                : hash('sha256', serialize($target)),
        ],
    );
}

function concurrencyTestDriver(): ?string
{
    $driver = app(DatabaseManager::class)->connection()->getDriverName();

    return in_array($driver, ['mysql', 'mariadb', 'pgsql'], true) ? $driver : null;
}

function concurrencyTestSkipReason(): string
{
    return 'Requires a real MySQL/MariaDB or PostgreSQL connection (DB_CONNECTION); SQLite has no REPEATABLE READ/SERIALIZABLE semantics and never raises SQLSTATE 40001.';
}

/**
 * Points discovery at fixture directories and re-runs the provider's boot. The app is already
 * booted by the time a test body runs, so the booted() callback the provider registers fires
 * immediately — which is what makes the wiring itself, not just the registrar, the thing under
 * test. Lives here because more than one Feature file boots the discovery path.
 */
function bootDiscovery(string ...$directories): void
{
    app()->instance(CapabilityDiscovery::class, new CapabilityDiscovery(
        rootPath: __DIR__.'/Fixtures',
        rootNamespace: 'Fissible\\Verdict\\Tests\\Fixtures\\',
        paths: array_map(static fn (string $d): string => __DIR__.'/Fixtures/'.$d, $directories),
    ));

    // The registrar the application already booted captured the real discovery, so replacing the
    // discovery alone would not reach it. Rebinding after first resolution is a test-only lifetime:
    // in production the registrar first resolves in booted(), after every provider has had its say,
    // and nothing re-binds discovery mid-process. This is not a papered-over production bug.
    app()->forgetInstance(CapabilityRegistrar::class);

    (new VerdictServiceProvider(app()))->boot();
}
