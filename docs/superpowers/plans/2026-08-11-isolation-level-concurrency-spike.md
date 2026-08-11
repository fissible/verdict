# Isolation-Level Concurrency Spike (#37) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Answer, with real measured evidence rather than documentation-based argument, the two open questions ADR 0016 Decision §6 names: (1) does `lockForUpdate()` under MySQL/InnoDB REPEATABLE READ behave equivalently to PostgreSQL under READ COMMITTED for Verdict's lock-then-insert-then-retry pattern, and (2) does PostgreSQL SERIALIZABLE raise SQLSTATE 40001 for this pattern, and if so does it surface as a 500 instead of a correct denial? Produce a follow-on ADR fixing the supported isolation levels and required exception handling, and land the CI strategy change (Postgres on every PR, full matrix on tag/weekly) that makes future regressions in this area visible.

**Architecture:** Genuine process-level concurrency — separate PHP CLI processes via `proc_open`, each with its own DB connection — driving Verdict's real `DatabaseRateLimitStore::consume()` and `DatabaseExecutionClaimStore::claim()` against a shared contended row, across four driver configurations (PostgreSQL READ COMMITTED, MySQL/InnoDB REPEATABLE READ, MySQL/InnoDB READ COMMITTED, MariaDB) via `docker-compose.spike.yml`, then again against PostgreSQL SERIALIZABLE specifically to observe SQLSTATE 40001 behavior. All spike code lives under `spikes/0037-isolation-level-concurrency/` — committed (not deleted after running), since the issue's own acceptance criteria treats "a spike that concludes 'it worked' without the transcript" as not evidence, and a script sitting next to its transcript in git history is the most durable form of that evidence. `spikes/` is excluded from `composer test`'s suites (not added to `phpunit.xml.dist`) since these are throwaway-by-design, hand-run scripts, not part of the durable regression suite (that's #20's job, informed by this spike's findings). The findings drive a new `docs/adr/0018-*.md`; ADR 0016 gets a short "Update" pointer to it, following the ADR 0006 precedent (durable decision records get a pointer note, not a rewrite).

**Tech Stack:** PHP 8.3, `illuminate/database` (Capsule, standalone — no full Laravel app needed for the spike scripts), Docker Compose, PostgreSQL 16, MySQL 8, MariaDB 11.

## Global Constraints

- Spike scripts are plain PHP CLI scripts (`declare(strict_types=1)`, no framework bootstrap beyond `vendor/autoload.php` + `Illuminate\Database\Capsule\Manager`), not Pest tests — they are not part of `composer test` and must not be added to `phpunit.xml.dist` or any `tests/` directory.
- Every driver config must be exercised via **separate OS processes with separate connections** (`proc_open`), never multiple transactions sharing one connection in one process — the issue and ADR 0016 are explicit that a same-connection "concurrency" test proves ordering, not concurrency.
- Record raw output for everything. A results file with only a conclusion and no transcript does not satisfy this issue's acceptance criteria.
- No changes to `src/` in this plan — ADR 0016's own "Non-goals" says the invariant describes code that already exists; this issue answers an open question, it does not implement a fix. If the spike reveals `src/` needs to change (e.g. SERIALIZABLE 40001 isn't being caught), that is out of scope here and becomes the follow-on ADR's stated next step, not something this plan implements.
- Docker containers use non-default host ports (5433, 3307, 3308) to avoid colliding with anything a contributor may already have running locally.

---

### Task 1: Docker Compose fixture and directory scaffold

**Files:**
- Create: `docker-compose.spike.yml`
- Create: `spikes/0037-isolation-level-concurrency/README.md`
- Create: `.gitignore` addition for `spikes/0037-isolation-level-concurrency/results/*.txt` (keep the directory, ignore generated transcripts — they get pasted into the GitHub issue and the ADR, not committed as loose files)

**Interfaces:**
- Produces: four running database services reachable at fixed host ports, each with a database/user/password a later task's bootstrap script can connect to by convention.

- [ ] **Step 1: Write `docker-compose.spike.yml`**

```yaml
services:
  postgres:
    image: postgres:16
    environment:
      POSTGRES_DB: verdict_spike
      POSTGRES_USER: verdict
      POSTGRES_PASSWORD: verdict
    ports:
      - "5433:5432"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U verdict -d verdict_spike"]
      interval: 2s
      timeout: 2s
      retries: 30

  mysql-repeatable-read:
    image: mysql:8
    environment:
      MYSQL_DATABASE: verdict_spike
      MYSQL_USER: verdict
      MYSQL_PASSWORD: verdict
      MYSQL_ROOT_PASSWORD: root
    command: ["--transaction-isolation=REPEATABLE-READ"]
    ports:
      - "3307:3306"
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-uroot", "-proot"]
      interval: 2s
      timeout: 2s
      retries: 30

  mysql-read-committed:
    image: mysql:8
    environment:
      MYSQL_DATABASE: verdict_spike
      MYSQL_USER: verdict
      MYSQL_PASSWORD: verdict
      MYSQL_ROOT_PASSWORD: root
    command: ["--transaction-isolation=READ-COMMITTED"]
    ports:
      - "3308:3306"
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-uroot", "-proot"]
      interval: 2s
      timeout: 2s
      retries: 30

  mariadb:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: verdict_spike
      MARIADB_USER: verdict
      MARIADB_PASSWORD: verdict
      MARIADB_ROOT_PASSWORD: root
    ports:
      - "3309:3306"
    healthcheck:
      # Not mysqladmin: the mariadb:11 image doesn't ship it at all (confirmed by exec — "executable
      # file not found in $PATH"). Its own image-provided healthcheck.sh is the correct tool here.
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 2s
      timeout: 2s
      retries: 30
```

- [ ] **Step 2: Write `spikes/0037-isolation-level-concurrency/README.md`**

```markdown
# Isolation-level concurrency spike (#37)

Throwaway-by-design, hand-run scripts answering ADR 0016 Decision §6's open question. Not part of
`composer test` — do not add these to `phpunit.xml.dist`.

## Running

    docker compose -f docker-compose.spike.yml up -d
    # wait for all four services to report healthy: docker compose -f docker-compose.spike.yml ps
    php spikes/0037-isolation-level-concurrency/bootstrap.php
    php spikes/0037-isolation-level-concurrency/spike-a.php | tee spikes/0037-isolation-level-concurrency/results/spike-a-$(date +%Y%m%d).txt
    php spikes/0037-isolation-level-concurrency/spike-b.php | tee spikes/0037-isolation-level-concurrency/results/spike-b-$(date +%Y%m%d).txt
    docker compose -f docker-compose.spike.yml down -v

## What each script does

- `bootstrap.php` — creates the three Verdict security-state tables on all four running databases.
- `spike-a.php` — driver equivalence: N concurrent processes racing `DatabaseRateLimitStore::consume()`
  against one bucket, and separately racing `DatabaseExecutionClaimStore::claim()` against one binding,
  on each of the four driver configs. Asserts the invariant holds (exactly `limit` admissions, exactly
  one claim winner) on every driver.
- `spike-b.php` — SERIALIZABLE behavior: the same race, but only against PostgreSQL with the
  transaction isolation level forced to `SERIALIZABLE`, recording whether SQLSTATE 40001 is raised, in
  which store, at what contention level, and whether it is currently handled or surfaces as an
  unhandled exception.

Results are `results/*.txt` (gitignored — paste into the GitHub issue and the follow-on ADR, don't
commit loose transcripts) plus a permanent summary in `docs/adr/0018-*.md` once written.
```

- [ ] **Step 3: Add the gitignore entry**

Append to `.gitignore`:

```
/spikes/0037-isolation-level-concurrency/results/*.txt
```

- [ ] **Step 4: Bring the fixture up and confirm all four services report healthy**

Run: `docker compose -f docker-compose.spike.yml up -d`
Then poll: `docker compose -f docker-compose.spike.yml ps`
Expected: all four services show `healthy` within ~60s. If any doesn't, `docker compose -f docker-compose.spike.yml logs <service>` before proceeding — do not move on with a service that isn't actually healthy, later steps will produce misleading connection-refused noise instead of real findings.

- [ ] **Step 5: Commit**

```bash
git add docker-compose.spike.yml spikes/0037-isolation-level-concurrency/README.md .gitignore
git commit -m "chore: add docker-compose fixture for the #37 isolation-level spike"
```

---

### Task 2: Migration bootstrap script

**Files:**
- Create: `spikes/0037-isolation-level-concurrency/lib/connections.php`
- Create: `spikes/0037-isolation-level-concurrency/bootstrap.php`

**Interfaces:**
- Produces: `connections(): array<string, array{driver: string, host: string, port: int, database: string, username: string, password: string, isolation_level: ?string}>` — the four (or five, once spike-b's SERIALIZABLE variant is added in Task 5) named connection configs every later script reads from, so the connection details exist in exactly one place.
- Produces: `capsule(array $config): \Illuminate\Database\Capsule\Manager` — builds a standalone Capsule connection from one entry of `connections()`.
- Consumes: nothing new — reads `database/migrations/create_verdict_rate_limit_buckets_table.php.stub`, `create_verdict_execution_claims_table.php.stub`, `create_verdict_approval_receipts_table.php.stub` directly (these are anonymous-class `Migration` subclasses using the `Schema`/`DB` facades; `require`-ing the stub file and calling `->up()` works standalone, but needs a real `Illuminate\Container\Container` with `'db'` and `'db.schema'` bound and passed to `Facade::setFacadeApplication()` first — `Capsule::setAsGlobal()` alone does not satisfy this, confirmed by running it without that step first (`RuntimeException: A facade root has not been set.`); see Step 2's code.

- [ ] **Step 1: Write the shared connection registry**

`spikes/0037-isolation-level-concurrency/lib/connections.php`:

```php
<?php

declare(strict_types=1);

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
function spike_capsule(array $config): \Illuminate\Database\Capsule\Manager
{
    $capsule = new \Illuminate\Database\Capsule\Manager;

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
```

- [ ] **Step 2: Write the bootstrap script**

`spikes/0037-isolation-level-concurrency/bootstrap.php`:

```php
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
    // 'db.schema') the stubs actually call. Confirmed by running this without the block below:
    // `RuntimeException: A facade root has not been set.` A real migration environment gets these
    // from Laravel's DatabaseServiceProvider; standalone here, bind them by hand.
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
```

- [ ] **Step 3: Run it and confirm all three tables exist on all five connections**

Run: `php spikes/0037-isolation-level-concurrency/bootstrap.php`
Expected: `migrated:` lines for all three stubs under all five connection headers (`postgres`, `postgres_serializable`, `mysql_repeatable_read`, `mysql_read_committed`, `mariadb` — `postgres_serializable` was folded into `spike_connections()` here rather than deferred to Task 5, since it costs nothing to add now and avoids a later edit to this file), ending in `Bootstrap complete.`, no exceptions. If a connection fails, re-check Task 1 Step 4's healthcheck output before assuming this script is wrong. Spot-check the actual schema on at least one connection (`docker exec <postgres-container> psql -U verdict -d verdict_spike -c '\d verdict_execution_claims'`) — confirm the primary key is on `id` and the unique constraint is on `binding_fingerprint`, not the reverse, since Task 4's child scripts depend on that distinction.

- [ ] **Step 4: Commit**

```bash
git add spikes/0037-isolation-level-concurrency/lib/connections.php spikes/0037-isolation-level-concurrency/bootstrap.php
git commit -m "feat: add spike bootstrap script to migrate security-state tables across drivers"
```

---

### Task 3: Concurrency harness (spawns real separate processes)

**Files:**
- Create: `spikes/0037-isolation-level-concurrency/lib/harness.php`

**Interfaces:**
- Produces: `spike_run_concurrent(string $childScriptPath, array $argvPerProcess): array<int, array{exit_code: int, stdout: string, stderr: string}>` — launches `count($argvPerProcess)` separate `php` CLI child processes in parallel via `proc_open`, passes each its own argv (JSON-encoded as a single CLI argument, since a child needs to know which connection + which shared fingerprint to race against), waits for every child to exit, returns each child's raw stdout/stderr/exit code in the same order `$argvPerProcess` was given.
- Consumes: nothing — this is a pure process-orchestration primitive, reused by both spike-a.php and spike-b.php.

- [ ] **Step 1: Write the harness**

`spikes/0037-isolation-level-concurrency/lib/harness.php`:

```php
<?php

declare(strict_types=1);

/**
 * Launches count($argvPerProcess) separate PHP CLI processes truly in parallel — proc_open forks a
 * real OS process per entry, each opening its own database connection, so this is genuine
 * process-level concurrency, not sequential calls or transactions sharing one connection.
 *
 * @param  array<int, array<string, mixed>>  $argvPerProcess  one JSON-encodable payload per child
 * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
 */
function spike_run_concurrent(string $childScriptPath, array $argvPerProcess): array
{
    $processes = [];

    foreach ($argvPerProcess as $index => $payload) {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cmd = ['php', $childScriptPath, json_encode($payload, JSON_THROW_ON_ERROR)];

        $process = proc_open($cmd, $descriptorSpec, $pipes);

        if ($process === false) {
            throw new RuntimeException("Failed to start child process {$index}.");
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $processes[$index] = ['process' => $process, 'pipes' => $pipes, 'stdout' => '', 'stderr' => ''];
    }

    $remaining = array_keys($processes);

    while ($remaining !== []) {
        foreach ($remaining as $index) {
            $processes[$index]['stdout'] .= (string) stream_get_contents($processes[$index]['pipes'][1]);
            $processes[$index]['stderr'] .= (string) stream_get_contents($processes[$index]['pipes'][2]);
        }

        $remaining = array_filter(
            $remaining,
            fn (int $index): bool => proc_get_status($processes[$index]['process'])['running'],
        );

        if ($remaining !== []) {
            usleep(5_000);
        }
    }

    $results = [];

    foreach ($processes as $index => $entry) {
        $entry['stdout'] .= (string) stream_get_contents($entry['pipes'][1]);
        $entry['stderr'] .= (string) stream_get_contents($entry['pipes'][2]);

        fclose($entry['pipes'][1]);
        fclose($entry['pipes'][2]);

        $exitCode = proc_close($entry['process']);

        $results[$index] = ['exit_code' => $exitCode, 'stdout' => $entry['stdout'], 'stderr' => $entry['stderr']];
    }

    return $results;
}
```

- [ ] **Step 2: Smoke-test the harness in isolation before building spike-a/b on top of it**

Create a throwaway child script inline to confirm parallelism (not committed — delete after this step):

```bash
cat > /tmp/spike-harness-smoketest-child.php <<'EOF'
<?php
$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
usleep(200_000);
fwrite(STDOUT, json_encode(['id' => $payload['id'], 'pid' => getmypid()]));
EOF
php -r '
require "spikes/0037-isolation-level-concurrency/lib/harness.php";
$start = microtime(true);
$results = spike_run_concurrent("/tmp/spike-harness-smoketest-child.php", array_map(fn ($i) => ["id" => $i], range(1, 10)));
$elapsed = microtime(true) - $start;
$pids = array_unique(array_map(fn ($r) => json_decode($r["stdout"], true)["pid"], $results));
printf("elapsed=%.2fs distinct_pids=%d\n", $elapsed, count($pids));
'
rm /tmp/spike-harness-smoketest-child.php
```

Expected: `elapsed` close to 0.2s (all 10 children sleeping in parallel), not ~2s (which would mean they ran sequentially), and `distinct_pids=10` (ten genuinely separate OS processes, confirming this is real process-level concurrency and not an illusion from a single process handling all ten payloads).

- [ ] **Step 3: Commit**

```bash
git add spikes/0037-isolation-level-concurrency/lib/harness.php
git commit -m "feat: add process-level concurrency harness for the isolation spike"
```

---

### Task 4: Spike A — driver equivalence (rate limits and execution claims)

**Files:**
- Create: `spikes/0037-isolation-level-concurrency/spike-a-rate-limit-child.php`
- Create: `spikes/0037-isolation-level-concurrency/spike-a-claim-child.php`
- Create: `spikes/0037-isolation-level-concurrency/spike-a.php`

**Interfaces:**
- Consumes: `spike_connections()`, `spike_capsule()` (Task 2), `spike_run_concurrent()` (Task 3). `Fissible\Verdict\RateLimits\DatabaseRateLimitStore`, `RateLimitConsumption` (constructor: `string $bucketFingerprint, int $limit, int $windowSeconds, DateTimeImmutable $at`). `Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore::claim(ExecutionClaim $claim): ExecutionClaimTransition` — confirmed against the real source (`src/ExecutionClaims/DatabaseExecutionClaimStore.php:39-61`). `ExecutionClaim`'s constructor is `(string $id, string $capability, string $policy, string $bindingFingerprint, ExecutionClaimStatus $status, int $attemptCount, DateTimeImmutable $claimedAt, ?DateTimeImmutable $completedAt, ?DateTimeImmutable $indeterminateAt, ?DateTimeImmutable $releasedAt, ?string $resolvedBy, ?string $resolutionReason, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt)`. `ExecutionClaimTransition::admitted(): bool` returns `true` iff the outcome is `ExecutionClaimOutcome::Claimed` — this is the exact "did I win" check to use in the child script, no need to compare enum cases by hand.
- **Critical:** `id` is the table's primary key (`$table->string('id', 64)->primary();` — caller-supplied, not auto-increment) while `bindingFingerprint` is the unique constraint actually being contended over. Every concurrent child must generate its **own** random `id` (e.g. `bin2hex(random_bytes(16))` per child) but pass the **same shared** `bindingFingerprint` — reusing one `id` across children would collide on the primary key instead of the binding-fingerprint unique constraint, silently testing the wrong thing.
- Produces: a pass/fail summary per driver, printed to stdout, for two invariants: "exactly `limit` rate-limit admissions across N concurrent consumers of one bucket" and "exactly 1 execution-claim winner across N concurrent claimants of one binding."

- [ ] **Step 1: Write the rate-limit child**

`spikes/0037-isolation-level-concurrency/spike-a-rate-limit-child.php`:

```php
<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

spike_capsule(spike_connections()[$payload['connection']]);

$store = new \Fissible\Verdict\RateLimits\DatabaseRateLimitStore(
    \Illuminate\Database\Capsule\Manager::connection(),
);

$at = new DateTimeImmutable($payload['at']);

try {
    $outcome = $store->consume(new \Fissible\Verdict\RateLimits\RateLimitConsumption(
        bucketFingerprint: $payload['bucket_fingerprint'],
        limit: $payload['limit'],
        windowSeconds: $payload['window_seconds'],
        at: $at,
    ));

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'allowed' => $outcome->allowed,
        'remaining' => $outcome->remaining,
    ], JSON_THROW_ON_ERROR));
} catch (\Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof \Illuminate\Database\QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
```

- [ ] **Step 2: Write the execution-claim child**

`spikes/0037-isolation-level-concurrency/spike-a-claim-child.php`:

```php
<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

spike_capsule(spike_connections()[$payload['connection']]);

$store = new \Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore(
    \Illuminate\Database\Capsule\Manager::connection(),
);

$at = new DateTimeImmutable($payload['at']);

try {
    $transition = $store->claim(new \Fissible\Verdict\ExecutionClaims\ExecutionClaim(
        id: bin2hex(random_bytes(16)),
        capability: 'spike.claim',
        policy: 'spike-policy',
        bindingFingerprint: $payload['binding_fingerprint'],
        status: \Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus::Claimed,
        attemptCount: 1,
        claimedAt: $at,
        completedAt: null,
        indeterminateAt: null,
        releasedAt: null,
        resolvedBy: null,
        resolutionReason: null,
        createdAt: $at,
        updatedAt: $at,
    ));

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'admitted' => $transition->admitted(),
        'outcome' => $transition->outcome->value,
    ], JSON_THROW_ON_ERROR));
} catch (\Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof \Illuminate\Database\QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
```

Note the deliberate `id: bin2hex(random_bytes(16))` per child (own primary key each) against the shared `binding_fingerprint` from the payload (the actual contended value) — see this task's Interfaces note on why swapping this would silently test the wrong constraint.

- [ ] **Step 3: Write the orchestrator**

`spikes/0037-isolation-level-concurrency/spike-a.php`:

```php
<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';
require __DIR__.'/lib/harness.php';

const CONCURRENCY = 20;
const RATE_LIMIT = 5;

// Excludes postgres_serializable deliberately: SERIALIZABLE may legitimately raise 40001 under
// contention, which would register as a false FAIL against this loop's normal-contention invariant.
// That's Spike B's job specifically (Task 5).
const DRIVERS_UNDER_TEST = ['postgres', 'mysql_repeatable_read', 'mysql_read_committed', 'mariadb'];

$overallPass = true;

foreach (DRIVERS_UNDER_TEST as $connectionName) {
    echo "\n########## {$connectionName} ##########\n";

    // --- Rate limit race ---
    // CHAR(64) column — use a real sha256 hex digest (64 chars), matching how production actually
    // generates fingerprints, so fixed-length CHAR padding behavior can't differ across drivers and
    // contaminate the comparison this spike exists to make.
    $bucketFingerprint = hash('sha256', random_bytes(16));
    $at = (new DateTimeImmutable)->format(DATE_ATOM);

    $payloads = array_fill(0, CONCURRENCY, [
        'connection' => $connectionName,
        'bucket_fingerprint' => $bucketFingerprint,
        'limit' => RATE_LIMIT,
        'window_seconds' => 60,
        'at' => $at,
    ]);

    $results = spike_run_concurrent(__DIR__.'/spike-a-rate-limit-child.php', $payloads);

    $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);
    $admitted = count(array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && $d['allowed']));
    $errors = array_filter($decoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false));

    $ratePass = $admitted === RATE_LIMIT && $errors === [];

    printf(
        "rate-limit: %d/%d admitted (expected %d), %d errors -> %s\n",
        $admitted,
        CONCURRENCY,
        RATE_LIMIT,
        count($errors),
        $ratePass ? 'PASS' : 'FAIL',
    );

    foreach ($errors as $e) {
        echo '  error: '.json_encode($e)."\n";
    }

    // --- Execution claim race ---
    $bindingFingerprint = hash('sha256', random_bytes(16));

    $claimPayloads = array_fill(0, CONCURRENCY, [
        'connection' => $connectionName,
        'binding_fingerprint' => $bindingFingerprint,
        'at' => $at,
    ]);

    $claimResults = spike_run_concurrent(__DIR__.'/spike-a-claim-child.php', $claimPayloads);
    $claimDecoded = array_map(fn ($r) => json_decode($r['stdout'], true), $claimResults);
    $winners = count(array_filter($claimDecoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['admitted'] ?? false)));
    $claimErrors = array_filter($claimDecoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false));

    $claimPass = $winners === 1 && $claimErrors === [];

    printf(
        "execution-claim: %d/%d winners (expected 1), %d errors -> %s\n",
        $winners,
        CONCURRENCY,
        count($claimErrors),
        $claimPass ? 'PASS' : 'FAIL',
    );

    foreach ($claimErrors as $e) {
        echo '  error: '.json_encode($e)."\n";
    }

    $overallPass = $overallPass && $ratePass && $claimPass;
}

echo "\n========== SPIKE A: ".($overallPass ? 'ALL DRIVERS PASS' : 'AT LEAST ONE FAILURE')." ==========\n";
```

- [ ] **Step 4: Run against the real fixture and record output**

Run: `php spikes/0037-isolation-level-concurrency/spike-a.php | tee spikes/0037-isolation-level-concurrency/results/spike-a-run1.txt`
Expected, going in: PASS on all four drivers, matching ADR 0016's stated intent. **If any driver FAILs, that is itself the finding** — do not treat it as a bug in the spike script without first checking the child processes' raw `error:` output for a real exception. Re-run at least twice more (deadlocks under InnoDB gap-locking are inherently probabilistic, not deterministic per-run) before concluding a driver genuinely fails.

**What actually happened across 3 runs:** `postgres` and `mysql_read_committed` passed cleanly all three times (0 errors, every race, every run). `mysql_repeatable_read` and `mariadb` — both InnoDB REPEATABLE READ, MySQL/MariaDB's default — failed on 2 of 3 runs each, always with the same signature: `SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock`, raised from the plain `insert into verdict_execution_claims (...)` / `insert into verdict_rate_limit_buckets (...)` statement inside `consume()`/`claim()`'s transaction, uncaught (only `UniqueConstraintViolationException` is caught today). This directly falsifies ADR 0016 Decision §6's stated intent ("correct at READ COMMITTED and... remains correct at stricter levels") — REPEATABLE READ is a **stricter** level than READ COMMITTED, and it is not, in fact, safe as currently implemented. This is the load-bearing finding for Task 7's ADR.

- [ ] **Step 5: Commit**

```bash
git add spikes/0037-isolation-level-concurrency/spike-a-rate-limit-child.php spikes/0037-isolation-level-concurrency/spike-a-claim-child.php spikes/0037-isolation-level-concurrency/spike-a.php
git commit -m "feat: add spike A — driver equivalence for rate limits and execution claims"
```

---

### Task 5: Spike B — PostgreSQL SERIALIZABLE and SQLSTATE 40001

**Files:**
- Create: `spikes/0037-isolation-level-concurrency/spike-b-child.php`
- Create: `spikes/0037-isolation-level-concurrency/spike-b.php`
- Modify: `spikes/0037-isolation-level-concurrency/lib/connections.php` (add a `postgres_serializable` entry)

**Interfaces:**
- Consumes: same as Task 4, plus a way to force the connection's transaction isolation level to `SERIALIZABLE` before the store's own `$connection->transaction(...)` call runs.
- Produces: a record of whether SQLSTATE 40001 is raised under contention against PostgreSQL SERIALIZABLE, for both the rate-limit and execution-claim stores, at contention levels 2, 5, and 20, and whether the existing `catch (UniqueConstraintViolationException)` in each store ever incorrectly catches it (it shouldn't — 40001 and a unique-constraint violation are different SQLSTATEs — but confirm rather than assume, since this is exactly the kind of claim Task requires measuring, not arguing).

- [ ] **Step 1: Add the SERIALIZABLE connection variant**

In `spikes/0037-isolation-level-concurrency/lib/connections.php`, add to the array `spike_connections()` returns:

```php
'postgres_serializable' => [
    'driver' => 'pgsql',
    'host' => '127.0.0.1',
    'port' => 5433,
    'database' => 'verdict_spike',
    'username' => 'verdict',
    'password' => 'verdict',
],
```

(Same physical database as `postgres` — the isolation level is set per-session by the child script below, not baked into the connection config, since `SET TRANSACTION ISOLATION LEVEL` is a session/transaction-scoped statement, not a connection-string parameter.)

- [ ] **Step 2: Write the SERIALIZABLE child**

`spikes/0037-isolation-level-concurrency/spike-b-child.php` — same structure as `spike-a-rate-limit-child.php`, but before calling `$store->consume(...)`, execute `\Illuminate\Database\Capsule\Manager::connection()->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');` so every transaction this connection opens for the rest of the process runs at SERIALIZABLE. Also add a `store` field to the JSON payload (`'rate_limit'` or `'claim'`) so one child script drives both stores, and branch on it — avoid duplicating the whole file for a one-line difference in which store gets constructed. On catching `\Throwable`, record `$e->getCode()` (the SQLSTATE Laravel's `QueryException` surfaces) explicitly, not just the exception class name — 40001 is the fact this whole spike exists to observe.

- [ ] **Step 3: Write the orchestrator**

`spikes/0037-isolation-level-concurrency/spike-b.php` — loops contention levels `[2, 5, 20]`, for each level runs both the rate-limit race and the claim race against `postgres_serializable` only (per the issue: "Same patterns against PostgreSQL SERIALIZABLE"), and prints, per level and per store: how many succeeded cleanly, how many threw, and — for every thrower — the exception class and SQLSTATE. Do not assert pass/fail here the way spike-a.php does; this spike's job is to *observe and record*, not to grade against an invariant that might not hold at SERIALIZABLE (that grading is exactly what the follow-on ADR in Task 7 has to decide, informed by what this prints).

- [ ] **Step 4: Run and record**

Run: `php spikes/0037-isolation-level-concurrency/spike-b.php | tee spikes/0037-isolation-level-concurrency/results/spike-b-run1.txt`
Expected: a full transcript showing, at each contention level, whether 40001 appears, and if so how the store currently handles it (uncaught `PDOException`/`QueryException` propagating out of `consume()`/`claim()`, versus something already handling it gracefully). This is the load-bearing evidence for Task 7 — re-run at least twice to confirm the pattern is consistent, not a one-off scheduling artifact of process startup order.

**What actually happened across 2 runs:** 40001 appears at every contention level tested (2, 5, 20), for both `DatabaseRateLimitStore::consume()` and `DatabaseExecutionClaimStore::claim()`, completely uncaught (only `UniqueConstraintViolationException` is caught). The invariant holds *among the responses that don't throw* (never more than `RATE_LIMIT` admitted, never more than 1 claim winner) — SERIALIZABLE isn't producing wrong answers, it's producing exceptions instead of answers for a large share of callers. Rate-limit was strikingly consistent both runs: exactly 1/2, 1/5, and 5/20 succeeded at each level, identically. Execution claims varied a little more at the highest contention level (18/20 succeeded then 20/20) but never exceeded 1 winner. This confirms, rather than merely anticipates, ADR 0016 Decision §6's named risk: "an operator who configures a dedicated connection at SERIALIZABLE... would see contention as a 500 rather than as a correct denial."

- [ ] **Step 5: Commit**

```bash
git add spikes/0037-isolation-level-concurrency/lib/connections.php spikes/0037-isolation-level-concurrency/spike-b-child.php spikes/0037-isolation-level-concurrency/spike-b.php
git commit -m "feat: add spike B — PostgreSQL SERIALIZABLE and SQLSTATE 40001 observation"
```

---

### Task 6: Post raw results to issue #37

**Files:** none — GitHub only.

- [ ] **Step 1: Assemble the results comment**

Combine `results/spike-a-run1.txt` and `results/spike-b-run1.txt` (plus any re-runs from Tasks 4–5's verification steps) into one comment body: a short summary line per spike, then the raw transcripts in fenced code blocks. Do not paraphrase the transcript away — the issue's acceptance criteria requires the raw output, not a conclusion.

- [ ] **Step 2: Post it**

```bash
gh issue comment 37 --repo fissible/verdict --body-file /path/to/assembled-results.md
```

- [ ] **Step 3: Commit the local results files for durability alongside the code that produced them**

The `.gitignore` entry from Task 1 excludes `results/*.txt` from routine commits (they're regenerable and would otherwise churn the repo on every re-run), but the ADR in Task 7 needs to quote them precisely — keep them on disk in this worktree through Task 7; do not delete them yet.

---

### Task 7: Follow-on ADR fixing supported isolation levels

**Files:**
- Create: `docs/adr/0018-<slug-chosen-from-actual-findings>.md` — pick the slug once the findings are in (e.g. `0018-serializable-conflicts-retry-like-unique-violations.md` if that's what Task 5 shows; do not pre-commit to a title before the evidence exists)
- Modify: `docs/adr/0016-one-contended-row-per-constraint.md` (add an "Update" note under Decision §6, do not rewrite it)

**Interfaces:** none — documentation only, informed entirely by Tasks 4–6's actual output.

- [ ] **Step 1: Draft the ADR structure, following this repo's existing ADR conventions**

Read `docs/adr/0016-one-contended-row-per-constraint.md` and `docs/adr/0006-streaming-approval-resumption-deferred.md` once more immediately before drafting, as style references (Status / Related issues / Context / Decision / Consequences / Alternatives rejected / Sources). The new ADR's **Decision** section must state, as concrete, checkable facts derived from Task 4/5's transcripts — not as aspirations:

- Which isolation levels are confirmed correct for the lock-then-insert-then-retry pattern (expected: READ COMMITTED and REPEATABLE READ, per Spike A, if it passed on all four drivers).
- What SERIALIZABLE actually does under contention (SQLSTATE 40001 or not; if it does, whether it's currently caught) — from Spike B.
- If 40001 does surface uncaught: the required fix shape, matching the issue's own reasoning already recorded in #37 — catch `40001` narrowly at the store boundary (not swallowing an application-level 40001, which must propagate per ADR 0004's independent-transaction boundary), bounded to one retry, mirroring the existing unique-violation retry precedent in the same three stores. State this as the **next required change**, not as something this ADR implements — ADR 0016's Non-goals precedent (state the invariant, defer the src/ change) applies here too, per this plan's Global Constraints.
- Whether the CI strategy in Task 8 is sufficient to catch a future regression in this area, or whether the ADR should name a stronger guarantee.

- [ ] **Step 2: Write the ADR file**

Use the next unused ADR number (0018, unless a concurrent PR has claimed it — check `ls docs/adr/` immediately before creating the file). Cite the actual transcript lines from `results/spike-a-run1.txt` / `results/spike-b-run1.txt` as evidence in the Context/Decision sections, the same way ADR 0016 cites exact file:line locations.

- [ ] **Step 3: Add the ADR 0016 update note**

Under Decision §6's existing final paragraph ("Until then, this ADR states the intent and marks the question open rather than asserting a guarantee."), add:

```markdown

**Update:** #37 measured this. See [ADR 0018](0018-<actual-filename>.md) for the confirmed isolation-level guarantees and required exception handling.
```

- [ ] **Step 4: Update the "Related issues" line in ADR 0016 and ADR 0004**

Change `- [#37](https://github.com/fissible/verdict/issues/37) (open) measures the isolation-level question...` to `(implemented)`, matching the convention already used for #19/#22 elsewhere in this repo's ADRs (verify the exact current wording in each file before editing, since these lines carry different trailing descriptions per ADR).

- [ ] **Step 5: Commit**

```bash
git add docs/adr/0018-*.md docs/adr/0016-one-contended-row-per-constraint.md docs/adr/0004-independent-security-state-transactions.md
git commit -m "docs: add ADR 0018 fixing isolation levels from the #37 spike"
```

---

### Task 8: CI strategy — Postgres on every PR, full matrix on tag/weekly

**Files:**
- Modify: `.github/workflows/tests.yml`
- Create: `.github/workflows/concurrency-matrix.yml`

**Interfaces:** none — CI configuration only.

- [ ] **Step 1: Read the current `tests.yml` in full**

Confirm the exact job names and matrix strategy already in place (the PHP/Laravel prefer-lowest/prefer-stable matrix seen in this repo's CI checks) before adding a Postgres service container, so the addition follows the existing job's conventions rather than introducing a second, differently-styled job.

- [ ] **Step 2: Add a PostgreSQL service container to the PR-triggered test job**

Add a `services:` block with a `postgres:16` container (health-checked, same shape as this plan's `docker-compose.spike.yml` Postgres service) to the job that currently runs the Pest suite against SQLite. Do not remove SQLite — per #37's Part 3, both run on every PR (SQLite for speed/coverage, Postgres as the one real engine that catches a concurrency regression where it was introduced). This likely means adding a step or env toggle that runs the suite a second time against the Postgres connection, or a parametrized job — follow whatever pattern keeps the existing SQLite run unchanged and adds the Postgres run alongside it, not replacing it.

- [ ] **Step 3: Create the scheduled/tag full-matrix workflow**

`.github/workflows/concurrency-matrix.yml` — triggers on `schedule: - cron: '0 6 * * 1'` (weekly, Monday) and `push: tags: ['v*']`, running the full driver matrix (PostgreSQL, MySQL, MariaDB) against `composer test`. This is a separate workflow file, not a job added to `tests.yml`, so it doesn't run on every push/PR.

- [ ] **Step 4: Verify the workflow YAML is well-formed**

Run: `docker run --rm -v "$PWD:/repo" rhysd/actionlint:latest -color` if `actionlint` is available; otherwise at minimum confirm both files parse as valid YAML (`php -r '$y = yaml_parse_file(".github/workflows/tests.yml"); var_dump($y !== false);'` or equivalent) before committing — a malformed workflow file fails silently until the next push.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/tests.yml .github/workflows/concurrency-matrix.yml
git commit -m "ci: run Postgres on every PR, full driver matrix on tag and weekly"
```

---

### Task 9: Final verification, cleanup, and PR

**Files:** none — verification only.

- [ ] **Step 1: Tear down the spike fixture**

Run: `docker compose -f docker-compose.spike.yml down -v`

- [ ] **Step 2: Full suite**

Run: `composer test`
Expected: 0 failures, 100% type coverage, pint clean, phpstan clean — confirms nothing under `spikes/` accidentally got pulled into the normal test discovery, and that the ADR/CI changes didn't break anything.

- [ ] **Step 3: Confirm the diff matches the plan's scope**

Run: `git diff <branch-start-commit> --stat` (use `git merge-base origin/main HEAD`, not local `main`, per this session's established gotcha with stale local branch refs).
Expected files: `docker-compose.spike.yml`, everything under `spikes/0037-isolation-level-concurrency/`, `.gitignore`, `docs/adr/0018-*.md`, `docs/adr/0016-one-contended-row-per-constraint.md`, `docs/adr/0004-independent-security-state-transactions.md`, `.github/workflows/tests.yml`, `.github/workflows/concurrency-matrix.yml`, plus this plan file. No `src/` changes — confirmed by this plan's Global Constraints.

- [ ] **Step 4: Push and open the PR**

```bash
git push -u origin <branch-name>
gh pr create --repo fissible/verdict --title "spike: measure isolation-level behavior for security-state concurrency (#37)" --body "Closes #37."
```

Fill in the PR body using `.github/PULL_REQUEST_TEMPLATE.md`. "Trust and failure behavior" should summarize the actual SERIALIZABLE finding from Task 5/7 plainly — if 40001 surfaces as an unhandled exception today, say so directly rather than softening it, since that's the whole point of the spike. "Verification" should note the spike scripts are throwaway-by-convention but kept in the repo for reproducibility, and are not part of `composer test`.

## Self-Review

**Spec coverage:** every acceptance-criteria bullet in #37 maps to a task — docker-compose fixture (Task 1), Spike A and Spike B using genuine process-level concurrency (Tasks 3–5), raw results posted to the issue (Task 6), a follow-on ADR fixing isolation levels/exception handling/retry bound (Task 7), CI running SQLite+Postgres on every PR and the full matrix on tag/weekly (Task 8).

**Placeholder scan:** the one deliberately open item is Task 7's ADR *content* and filename slug, which cannot be written before Tasks 4–6 produce real findings — this is not a placeholder in the "TBD" sense the writing-plans skill warns against; it is the honest shape of a spike whose entire purpose is that the answer isn't known yet. Every other task has complete, runnable code.

**Type consistency:** `spike_connections()`, `spike_capsule()`, `spike_run_concurrent()` are defined once (Tasks 2–3) and consumed identically by every later script (Tasks 4–5). Task 4's Interfaces section explicitly flags the one place this plan could not verify a signature against the real source before writing (`DatabaseExecutionClaimStore::claim()`'s exact parameters and return type) and instructs the implementer to read the real file first rather than guess — called out rather than silently assumed.
