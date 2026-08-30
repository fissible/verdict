<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * The per-PR MySQL lane, and the bound on what it runs (#397).
 *
 * Engine-specific defects reached a release with no PR-time gate. The cross-engine matrix is
 * weekly and on-tag, and `release.yml` cuts the release regardless of its result.
 *
 * WHAT THE LANE IS ACTUALLY FOR, measured rather than assumed. #397 justifies it by the v0.13.0
 * derivations read-order defect (#383), and a mutation probe against real engines says otherwise:
 * removing that fix's tiebreakers is caught by SQLite and missed by both MySQL and PostgreSQL,
 * because InnoDB clusters the derivations table on its composite primary key and that order
 * already satisfies what the tiebreakers guarantee. #383 is held by the SQLite lane that already
 * runs on every pull request.
 *
 * The lane earns its place on ground no other per-PR lane holds: MySQL's identifier-length limit,
 * InnoDB under REPEATABLE READ, and MySQL/MariaDB session-timezone conversion. #389's row-ordering
 * defect is MySQL-only too, but the probe caught it in six runs of eight — it depends on whether a
 * generated uuid happens to sort last — so it is a probe this lane can expose, not a regression it
 * gates.
 *
 * The lane is deliberately NOT the whole suite. PostgreSQL already runs everything per PR; MySQL's
 * job here is to be a maintained InnoDB discriminator, and #397 asks for the smallest set whose
 * outcome actually differs by engine. This file is the anti-drift mechanism for that bound: it
 * pins the list exactly, so growing it, shrinking it, or quietly dropping the lane out of the
 * required gate is a deliberate paired change rather than an unnoticed one. It does not forbid the
 * list from evolving — it forbids it evolving silently.
 *
 * WHAT THIS FILE CANNOT DO. It reads the workflow; it never runs it. That a red slice actually
 * fails the lane is discharged by mutation against a real MySQL 8 — remove #383's tiebreakers,
 * watch `DeterministicDerivationReadOrderTest` fail on MySQL, restore, watch the slice go green.
 * A conformance test that only reads YAML would be satisfied by a lane that runs nothing.
 *
 * @return array<string, mixed>
 */
function mysqlLaneWorkflow(): array
{
    // symfony/yaml, not the yaml extension: the extension is not installed here, and a test that
    // errors on a missing function asserts nothing about the workflow. Same choice, same reason,
    // as tests/Unit/ContractSuiteConformanceTest.php.
    $parsed = Yaml::parse(mysqlLaneWorkflowSource());

    expect($parsed)->toBeArray('The tests workflow must remain parseable YAML.');

    return (array) $parsed;
}

/**
 * `continue-on-error` accepts an expression, so `${{ false }}` is behaviourally false while a plain
 * cast reads the non-empty string as true. Judge the value, not its spelling.
 */
function mysqlLaneContinuesOnError(mixed $value): bool
{
    if (is_string($value)) {
        $value = trim(str_replace(['${{', '}}'], '', $value));

        return ! in_array(strtolower($value), ['false', '0', ''], true);
    }

    return (bool) $value;
}

function mysqlLaneWorkflowSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/tests.yml');
}

/**
 * The engine-discriminating slice, as this repository declares it.
 *
 * Each entry is here because its outcome depends on the engine, not because it touches a database.
 *
 * @return list<string>
 */
function mysqlLaneSlice(): array
{
    return [
        // MySQL's identifier-length constraint.
        'tests/Feature/DatabaseRateLimitStoreTest.php',
        // Migration and index semantics against a real engine (#315's derived names).
        'tests/Feature/DerivedIndexNamesTest.php',
        'tests/Feature/SchemaMigrationAssertionsTest.php',
        // Published incident-response SQL, which has to hold in each dialect.
        'tests/Feature/IncidentResponseQueriesTest.php',
        // MySQL's REPEATABLE READ default, which PostgreSQL's READ COMMITTED lane cannot show.
        'tests/Feature/SecurityStateConcurrencyRetryTest.php',
        // Session-timezone conversion on MySQL/MariaDB (#362).
        'tests/Feature/ConnectionTimezoneEvidenceTest.php',
        // The deploy-time assertion over that same conversion (#309): only a real session zone can
        // be wrong, so only this engine can prove the check reads one.
        'tests/Feature/SessionTimezoneAuditTest.php',
        // The two defects that motivated the lane.
        'tests/Feature/DeterministicDerivationReadOrderTest.php',
        'tests/Feature/EvidenceColumnDegradationTest.php',
    ];
}

it('runs the tests workflow on pull requests', function (): void {
    // A lane that never runs on a pull request cannot gate one, however it is configured below.
    $on = mysqlLaneWorkflow()['on'] ?? mysqlLaneWorkflow()[true] ?? null;

    expect($on)->toBeArray('The tests workflow must declare its triggers.')
        ->and(array_key_exists('pull_request', (array) $on))->toBeTrue('The tests workflow must run on pull_request.');

    // Presence alone is weak: a path filter would leave the trigger in place while excusing exactly
    // the pull requests that change `src/`. The lane has to run on all of them.
    $pullRequest = (array) ((array) $on)['pull_request'];

    expect(array_key_exists('paths', $pullRequest))->toBeFalse('The tests workflow must not filter pull requests by path.')
        ->and(array_key_exists('paths-ignore', $pullRequest))->toBeFalse('The tests workflow must not exclude pull requests by path.');
});

it('declares a MySQL job that cannot be skipped or excused', function (): void {
    $job = mysqlLaneWorkflow()['jobs']['mysql'] ?? null;

    expect($job)->toBeArray('The tests workflow must declare a `mysql` job.');

    $job = (array) $job;

    // `if:` would let it be skipped and still report success; `continue-on-error` would let it fail
    // and report success. Either turns a required check into a decoration.
    // A `needs:` on this job makes it skippable: GitHub skips a dependent job when what it needs
    // fails or is skipped, and a skipped job is not a failed one.
    expect(array_key_exists('needs', $job))->toBeFalse('The MySQL lane must not depend on another job.')
        ->and(array_key_exists('if', $job))->toBeFalse('The MySQL lane must not be conditional.')
        ->and(mysqlLaneContinuesOnError($job['continue-on-error'] ?? false))->toBeFalse('The MySQL lane must not continue on error.');

    // The same two escapes exist one level down, and a step carrying either is the same decoration
    // wearing a smaller hat: `if: false` on the pest step leaves the job green having run nothing.
    // Judged by VALUE for continue-on-error, so an explicit `false` is allowed; `if` must be absent,
    // which is a canonical-shape rule rather than a behavioural one.
    foreach ((array) ($job['steps'] ?? []) as $index => $step) {
        expect(mysqlLaneContinuesOnError(((array) $step)['continue-on-error'] ?? false))
            ->toBeFalse("Step {$index} of the MySQL lane must not continue on error.")
            ->and(array_key_exists('if', (array) $step))
            ->toBeFalse("Step {$index} of the MySQL lane must not be conditional.");
    }

    // Against a real MySQL, on the driver the defects live under. A lane that quietly ran SQLite
    // would pass every assertion about paths while discriminating nothing.
    //
    // Read structurally, not as a substring over the serialized job: `mysql:8` appearing in an env
    // value or a step name would satisfy a text search while the service was something else.
    // (`toContain()` is also variadic in Pest — a trailing message is asserted as another needle —
    // so every message-carrying check here is an explicit predicate.)
    $images = array_map(
        static fn (array $service): string => (string) ($service['image'] ?? ''),
        array_map(static fn (mixed $service): array => (array) $service, (array) ($job['services'] ?? [])),
    );

    expect(array_filter($images, static fn (string $image): bool => str_starts_with($image, 'mysql:8')))
        ->not->toBeEmpty('The MySQL lane must run against a real mysql:8 service.');

    expect((string) ($job['env']['DB_CONNECTION'] ?? ''))->toBe('mysql', 'The MySQL lane must point the suite at the mysql connection.');

    // Likewise the driver: read the setup-php step's declared extensions, not the whole job as text,
    // so `echo pdo_mysql` in some unrelated script cannot stand in for actually installing it.
    $extensions = '';

    foreach ((array) ($job['steps'] ?? []) as $step) {
        if (str_contains((string) (((array) $step)['uses'] ?? ''), 'setup-php')) {
            $extensions = (string) (((array) $step)['with']['extensions'] ?? '');
        }
    }

    $installed = array_map(trim(...), explode(',', $extensions));

    expect(in_array('pdo_mysql', $installed, true))->toBeTrue('The MySQL lane must install the pdo_mysql extension.');

    // Job-level env says mysql; a step-level env would quietly win. A lane running SQLite beside an
    // idle mysql:8 service satisfies every static assertion above and discriminates nothing.
    foreach ((array) ($job['steps'] ?? []) as $index => $step) {
        $stepConnection = ((array) $step)['env'] ?? [];
        $stepConnection = ((array) $stepConnection)['DB_CONNECTION'] ?? 'mysql';

        expect((string) $stepConnection)->toBe('mysql', "Step {$index} of the MySQL lane must not redirect DB_CONNECTION.");
    }
});

it('runs exactly the declared slice, neither the whole suite nor a subset of it', function (): void {
    $steps = (array) (mysqlLaneWorkflow()['jobs']['mysql']['steps'] ?? []);

    // The paths have to be on the PEST COMMAND, not merely somewhere in the job. Concatenating
    // every `run` would be satisfied by a full-suite `vendor/bin/pest` beside an `echo` listing the
    // eight files — which is the exact lane this test exists to forbid.
    $pestSteps = array_values(array_filter(
        array_map(static fn (array $step): string => (string) ($step['run'] ?? ''), $steps),
        static fn (string $run): bool => str_contains($run, 'vendor/bin/pest'),
    ));

    expect($pestSteps)->toHaveCount(1, 'The MySQL lane must invoke the suite binary exactly once.');

    $script = $pestSteps[0];

    // The step runs ONE command, and that command is the binary followed by paths — nothing before
    // it, nothing after it. Anything looser leaves a lane that is green having run nothing:
    //
    //   vendor/bin/pest --list-tests <eight paths>     names the slice, executes none of it
    //   vendor/bin/pest --filter nope <eight paths>    names the slice, runs a subset
    //   vendor/bin/pest <eight paths> || true          runs it, swallows the failure
    //   DB_CONNECTION=sqlite vendor/bin/pest <paths>   runs it against the wrong engine
    //
    // A denylist of flags and operators would be a guess at the next one. Pinning the shape is not.
    // This is the same canonical-form choice as the literal path list below, for the same reason:
    // the lane's behaviour should be readable in the file rather than reasoned about.
    $lines = array_values(array_filter(
        array_map(trim(...), explode("\n", $script)),
        static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    ));

    expect($lines)->toHaveCount(1, 'The MySQL lane must run exactly one command.');

    $command = $lines[0];

    expect(preg_match('#^vendor/bin/pest(\s+tests/[A-Za-z0-9_/]+\.php)+$#', $command))
        ->toBe(1, 'The MySQL lane must run the suite binary with paths and nothing else — no flags, no prefix, no continuation.');

    // The command text can be exactly right and still not be what runs. `shell: bash {0} || true`
    // wraps it and swallows the failure; `working-directory` points the relative binary somewhere
    // else. Both are step or defaults keys rather than script text, so pinning the script alone
    // does not reach them.
    $pestStep = [];

    foreach ((array) $steps as $step) {
        if (str_contains((string) (((array) $step)['run'] ?? ''), 'vendor/bin/pest')) {
            $pestStep = (array) $step;
        }
    }

    expect(array_key_exists('shell', $pestStep))->toBeFalse('The MySQL lane must run in the default shell.')
        ->and(array_key_exists('working-directory', $pestStep))->toBeFalse('The MySQL lane must run from the workspace root.');

    foreach ([mysqlLaneWorkflow(), (array) (mysqlLaneWorkflow()['jobs']['mysql'] ?? [])] as $scope) {
        $defaults = (array) (((array) $scope)['defaults']['run'] ?? []);

        expect(array_key_exists('shell', $defaults))->toBeFalse('The MySQL lane must not inherit a custom shell.')
            ->and(array_key_exists('working-directory', $defaults))->toBeFalse('The MySQL lane must not inherit a working directory.');
    }

    // And no earlier step may rewrite the environment the suite runs under: a write to $GITHUB_ENV
    // applies to every step after it, so the declarative `env` checks above would not see it.
    foreach ((array) $steps as $index => $step) {
        expect(str_contains((string) (((array) $step)['run'] ?? ''), 'GITHUB_ENV'))
            ->toBeFalse("Step {$index} of the MySQL lane must not rewrite the environment for later steps.");
    }

    preg_match_all('#tests/[A-Za-z0-9_/]+\.php#', $command, $matches);

    // Every declared file is named on that invocation...
    foreach (mysqlLaneSlice() as $path) {
        expect(in_array($path, $matches[0], true))->toBeTrue("The MySQL lane must run {$path}.");
    }

    // ...and nothing else is. Counting the paths the invocation names keeps an extra file from
    // riding along: without this the lane could grow into the full matrix one path at a time,
    // which is the outcome #397 explicitly bounds against.
    //
    // The paths must be written LITERALLY in the workflow — not assembled from a variable, a
    // generated file list, or a matrix. That is a deliberate constraint, not an accident of how
    // this test is written: the whole point of the bound is that a reader opening the workflow can
    // see what the lane runs, and an indirection puts the answer somewhere they have to go look.
    $named = array_values(array_unique($matches[0]));
    sort($named);

    $declared = mysqlLaneSlice();
    sort($declared);

    expect($named)->toBe($declared, 'The MySQL lane must name exactly the declared slice.')
        ->and($matches[0])->toHaveCount(count($declared), 'The MySQL lane must name each slice file once.');
});

it('blocks the merge through the aggregation check', function (): void {
    // `ci-success` is the one check branch protection names — the workflow says so itself — so a
    // lane missing from its `needs` is a lane that runs, fails, and merges anyway.
    $ciSuccess = (array) (mysqlLaneWorkflow()['jobs']['ci-success'] ?? []);
    $needs = array_map(strval(...), (array) ($ciSuccess['needs'] ?? []));

    expect(in_array('mysql', $needs, true))->toBeTrue('The aggregation check must depend on the MySQL lane.');

    // And the aggregation must still report a dependency's failure rather than inheriting a skip.
    expect((string) ($ciSuccess['if'] ?? ''))->toBe('always()', 'The aggregation check must run even when a dependency fails.');

    $script = implode("\n", array_map(
        static fn (array $step): string => (string) ($step['run'] ?? ''),
        (array) ($ciSuccess['steps'] ?? []),
    ));

    // The words alone would be satisfied by an echo. The script has to actually read the
    // dependencies' results, and reject a SKIPPED one too — a required lane that never ran is not a
    // lane that passed, and skipping is exactly how a job disappears without failing.
    // The predicates themselves, not the bare words: `echo "failure cancelled skipped"` would
    // satisfy a word search. A skipped dependency counts — a required lane that never ran is not a
    // lane that passed, and skipping is how a job disappears without failing.
    foreach (['failure', 'cancelled', 'skipped'] as $result) {
        expect(str_contains($script, "contains(needs.*.result, '{$result}')"))
            ->toBeTrue("The aggregation must reject a {$result} dependency.");
    }

    // Naming them proves nothing if the script cannot exit nonzero.
    expect(str_contains($script, 'exit 1'))->toBeTrue('The aggregation must be able to fail.');

    expect(mysqlLaneContinuesOnError($ciSuccess['continue-on-error'] ?? false))
        ->toBeFalse('The aggregation check must not continue on error.');

    // The same shell wrapper that would neuter the lane would neuter the aggregation: a custom
    // `shell: bash {0} || true` swallows the `exit 1` above and the job reports success with the
    // literal predicates intact. `working-directory` is immaterial here, so it is not banned for
    // symmetry's sake.
    expect(array_key_exists('shell', (array) ($ciSuccess['defaults']['run'] ?? [])))
        ->toBeFalse('The aggregation check must not set a custom shell.');

    // And its verification step cannot excuse or skip itself either — `exit 1` under
    // `continue-on-error: true` is a check that fails and reports success.
    foreach ((array) ($ciSuccess['steps'] ?? []) as $index => $step) {
        expect(mysqlLaneContinuesOnError(((array) $step)['continue-on-error'] ?? false))
            ->toBeFalse("Step {$index} of the aggregation check must not continue on error.")
            ->and(array_key_exists('if', (array) $step))
            ->toBeFalse("Step {$index} of the aggregation check must not be conditional.")
            ->and(array_key_exists('shell', (array) $step))
            ->toBeFalse("Step {$index} of the aggregation check must run in the default shell.");
    }
});

it('leaves the cross-engine matrix scheduled and unblocking', function (): void {
    // #397 changes the PR gate, not the matrix. If the matrix became a PR check this lane would be
    // pointless, and if it lost its schedule the broad coverage this slice is narrow *because of*
    // would be gone.
    $matrix = (array) Yaml::parse((string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/concurrency-matrix.yml'));
    $on = (array) ($matrix['on'] ?? $matrix[true] ?? []);

    expect(array_key_exists('pull_request', $on))->toBeFalse('The cross-engine matrix must not become a pull-request check.');

    // Non-empty, and still on tags: an empty `schedule:` key or a push trigger pointed at branches
    // rather than `v*` would satisfy mere presence while the broad coverage this slice is narrow
    // BECAUSE OF quietly stopped happening.
    expect((array) ($on['schedule'] ?? []))->not->toBeEmpty('The cross-engine matrix must keep a real schedule.');

    $tags = array_map(strval(...), (array) (($on['push'] ?? [])['tags'] ?? []));

    expect(in_array('v*', $tags, true))->toBeTrue('The cross-engine matrix must stay on tag push.');
});

it('states the bound in the workflow, where the next person changing it will read it', function (): void {
    // A rule recorded only in this test is one the person widening the slice never sees. The
    // workflow has to say what the lane is for and what it deliberately excludes.
    $source = mysqlLaneWorkflowSource();

    // What the lane must state is the GROUND it holds that no other per-PR lane does. It must not be
    // required to name #383: that requirement encoded a premise the mutation probe falsified —
    // removing #383's tiebreakers is caught by SQLite (3 failed) and missed by both MySQL and
    // PostgreSQL, because InnoDB clusters the derivations table on the composite primary key and
    // that order already satisfies the assertions the tiebreakers exist to guarantee. A lane whose
    // stated purpose is a regression it does not detect is worse than no stated purpose.
    expect(str_contains($source, '#397'))->toBeTrue('The MySQL lane must name the issue that bounds it.')
        ->and(str_contains($source, 'REPEATABLE READ'))->toBeTrue(
            'The MySQL lane must name a ground it holds that the SQLite and PostgreSQL lanes do not.',
        )
        ->and(str_contains($source, 'concurrency-matrix'))->toBeTrue(
            'The MySQL lane must point a reader at the matrix that still carries full coverage.',
        );
});
