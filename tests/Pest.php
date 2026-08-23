<?php

declare(strict_types=1);

use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Tests\AttestTestCase;
use Fissible\Verdict\Tests\TestCase;
use Fissible\Verdict\Tests\WorkbenchTestCase;
use Illuminate\Database\DatabaseManager;

uses(TestCase::class)->in('Feature');
uses(WorkbenchTestCase::class)->in('Workbench');
uses(AttestTestCase::class)->in('Integration');

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
