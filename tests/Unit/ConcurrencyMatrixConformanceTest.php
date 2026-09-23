<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * #492 pins pre-merge full-suite coverage, not release-commit verification. Required-check and
 * v* tag policies are admin settings outside this repo. These tests inspect the workflow and
 * execute its aggregate shell; only CI on the real services supplies database runtime evidence.
 *
 * @return array<string, mixed>
 */
function concurrencyMatrixWorkflow(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/concurrency-matrix.yml');
}

/** @return array<string, mixed> */
function concurrencyMatrixJob(string $id): array
{
    $workflow = concurrencyMatrixWorkflow();
    $job = $workflow['jobs'][$id] ?? null;

    expect($job)->toBeArray();

    // Pin the default runner/shell/root and forbid error suppression at either level. A correct
    // command with a wrapper that swallows failures or runs another checkout is not evidence.
    foreach ([$workflow, $job] as $scope) {
        expect($scope)->not->toHaveKey('defaults');
    }

    expect($job)->not->toHaveKeys(['continue-on-error', 'strategy', 'container'])
        ->and($job['runs-on'])->toBe('ubuntu-latest');

    foreach ($job['steps'] as $step) {
        expect($step)->not->toHaveKeys(['if', 'continue-on-error', 'shell', 'working-directory']);
    }

    return $job;
}

it('runs the full database matrix on all PRs and main without path filters', function (): void {
    $on = concurrencyMatrixWorkflow()['on'];

    // Exact trigger configuration excludes both paths and paths-ignore (and branch-filtered PRs).
    expect($on)->toHaveKeys(['pull_request', 'push', 'schedule', 'workflow_dispatch'])
        ->and($on['pull_request'])->toBeNull()
        ->and($on['push'])->toBe(['branches' => ['main'], 'tags' => ['v*']])
        ->and($on['schedule'])->toBe([['cron' => '0 6 * * 1']])
        ->and($on['workflow_dispatch'])->toBeNull();
});

it('cancels superseded runs without conflating workflows events or refs', function (): void {
    expect(concurrencyMatrixWorkflow()['concurrency'])->toBe([
        'group' => '${{ github.workflow }}-${{ github.event_name }}-${{ github.ref }}',
        'cancel-in-progress' => true,
    ]);
});

it('runs an unconditional unfiltered suite on each real engine with tags and UTC setup', function (
    string $id,
    string $image,
    string $connection,
    string $extension,
): void {
    $job = concurrencyMatrixJob($id);

    expect($job)->not->toHaveKeys(['if', 'needs'])
        ->and($job['services']['db']['image'])->toBe($image)
        ->and($job['env']['DB_CONNECTION'])->toBe($connection);

    $checkoutSteps = [];
    $phpSteps = [];
    $pestSteps = [];
    $utcSteps = [];

    foreach ($job['steps'] as $index => $step) {
        expect($step['env']['DB_CONNECTION'] ?? $connection)->toBe($connection);

        if (str_starts_with($step['uses'] ?? '', 'actions/checkout@')) {
            $checkoutSteps[$index] = $step;
        }

        if (str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@')) {
            $phpSteps[$index] = $step;
        }

        if (str_contains($step['run'] ?? '', 'vendor/bin/pest')) {
            $pestSteps[$index] = $step;
        }

        if (str_contains($step['run'] ?? '', 'SET GLOBAL time_zone')) {
            $utcSteps[$index] = $step;
        }
    }

    expect($checkoutSteps)->toHaveCount(1)
        ->and(array_values($checkoutSteps)[0]['with']['fetch-tags'] ?? false)->toBeTrue()
        ->and($phpSteps)->toHaveCount(1)
        ->and($pestSteps)->toHaveCount(1);

    $phpStep = array_values($phpSteps)[0];
    $pestStep = array_values($pestSteps)[0];

    expect(array_map(trim(...), explode(',', $phpStep['with']['extensions'])))->toContain($extension)
        // Exact command: no --filter, file subset, --list-tests, env override, echo, or || true.
        ->and(trim($pestStep['run']))->toBe('vendor/bin/pest')
        ->and(array_key_first($checkoutSteps))->toBeLessThan(array_key_first($pestSteps))
        ->and(array_key_first($phpSteps))->toBeLessThan(array_key_first($pestSteps));

    if ($connection !== 'pgsql') {
        // Pin the executed setup, not a comment or echo mentioning UTC. PostgreSQL's service
        // defaults to UTC; MySQL/MariaDB need this global setting before suite connections open.
        $utcCommand = <<<'SH'
php -r "(new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'root'))->exec(\"SET GLOBAL time_zone = '+00:00'\");"
SH;

        expect($utcSteps)->toHaveCount(1)
            ->and(trim(array_values($utcSteps)[0]['run']))->toBe($utcCommand)
            ->and(array_key_first($utcSteps))->toBeGreaterThan(array_key_first($phpSteps))
            ->and(array_key_first($utcSteps))->toBeLessThan(array_key_first($pestSteps));
    }
})->with([
    'PostgreSQL' => ['postgres', 'postgres:16', 'pgsql', 'pdo_pgsql'],
    'MySQL' => ['mysql-repeatable-read', 'mysql:8', 'mysql', 'pdo_mysql'],
    'MariaDB' => ['mariadb', 'mariadb:11', 'mariadb', 'pdo_mysql'],
]);

it('always aggregates all three engines and succeeds only when every engine succeeded', function (): void {
    $job = concurrencyMatrixJob('database-matrix-success');

    expect($job['name'])->toBe('Database matrix success')
        ->and($job['needs'])->toBe(['postgres', 'mysql-repeatable-read', 'mariadb'])
        ->and($job['if'])->toBe('always()')
        ->and($job['steps'])->toHaveCount(1);

    $step = $job['steps'][0];

    expect($step['env'])->toBe([
        'POSTGRES_RESULT' => '${{ needs.postgres.result }}',
        'MYSQL_RESULT' => '${{ needs.mysql-repeatable-read.result }}',
        'MARIADB_RESULT' => '${{ needs.mariadb.result }}',
    ]);

    // Exercise the actual shell with all 64 result combinations. Merely searching for result
    // names or "exit 1" would accept a script that echoes the predicates or inverts their logic.
    $results = ['success', 'failure', 'cancelled', 'skipped'];

    foreach ($results as $postgres) {
        foreach ($results as $mysql) {
            foreach ($results as $mariadb) {
                $process = new Process(['bash', '-e', '-c', $step['run']], env: [
                    'POSTGRES_RESULT' => $postgres,
                    'MYSQL_RESULT' => $mysql,
                    'MARIADB_RESULT' => $mariadb,
                ]);
                $process->run();

                expect($process->getExitCode())->toBe(
                    $postgres === 'success' && $mysql === 'success' && $mariadb === 'success' ? 0 : 1,
                    "postgres={$postgres}, mysql={$mysql}, mariadb={$mariadb}: ".$process->getOutput(),
                );
            }
        }
    }
});
