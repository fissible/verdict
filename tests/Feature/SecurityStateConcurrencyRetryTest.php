<?php

declare(strict_types=1);

use Fissible\Verdict\Tests\Support\ConcurrencyHarness;
use Illuminate\Database\DatabaseManager;

/**
 * Genuine process-level concurrency (separate proc_open OS processes, separate connections — not
 * sequential calls or transactions sharing one connection) proving the SQLSTATE 40001 retry fix
 * (#86) against real MySQL, MariaDB, and PostgreSQL, per #37's measured findings and ADR 0018's
 * decision. This suite requires a real, network-reachable MySQL/MariaDB or PostgreSQL connection —
 * it cannot run meaningfully against SQLite, which has no REPEATABLE READ/SERIALIZABLE isolation
 * semantics and never raises SQLSTATE 40001. It self-skips when the configured connection isn't
 * one of those, so `composer test`'s default SQLite run is unaffected; the dedicated `postgres` CI
 * job and the `concurrency-matrix.yml` workflow (added in #87) are what actually exercise it.
 *
 * Adapted from spikes/0037-isolation-level-concurrency/ (#37) into a durable, permanent test, per
 * #86's acceptance criteria — the throwaway spike is not the last word on this; this is.
 *
 * **Every child process forces its lazy PDO connection before it signals readiness.**
 * `Connection::$pdo` starts as a Closure and is only resolved on first use; without an explicit
 * `getPdo()` call, a "ready" child could still be mid-connect, reintroducing the unsynchronized-
 * startup variance synchronization exists to eliminate. Found in review of this suite's first
 * version — see `tests/Support/concurrency-children/*.php`.
 *
 * **The batch is released by a real ready/release handshake, not a fixed wall-clock buffer.**
 * The first version of this suite computed a fixed start-at timestamp before spawning any children,
 * sized from measured p95 boot/connect latency — a statistical guess, not a guarantee, that a
 * slower-than-usual child could silently miss, starting its store call late and understating
 * contention without the test being able to tell. `ConcurrencyHarness` was corrected, in the same
 * review round that found the connection-forcing gap above, to a handshake: every child signals
 * readiness only after forcing its connection, and the harness releases the whole batch only once
 * every child has signaled. See `ConcurrencyHarness::releaseWhenAllReady()`.
 *
 * **Every supported engine/isolation result is strict, based on direct measurement with the
 * corrected harness — not by assumption.** MySQL, MariaDB, PostgreSQL READ COMMITTED, and the
 * dedicated PostgreSQL SERIALIZABLE case must return a clean response for every contender. See ADR
 * 0018 for the measured evidence.
 */
const CONCURRENCY_TEST_CONCURRENCY = 20;

const CONCURRENCY_TEST_RATE_LIMIT = 5;

function concurrencyTestDriver(): ?string
{
    $driver = config('database.default');

    return in_array($driver, ['mysql', 'pgsql'], true) ? $driver : null;
}

/** @return array<string, mixed> */
function concurrencyTestConnectionConfig(): array
{
    $driver = concurrencyTestDriver();

    return config("database.connections.{$driver}");
}

function concurrencyTestSkipReason(): string
{
    return 'Requires a real MySQL/MariaDB or PostgreSQL connection (DB_CONNECTION); SQLite has no REPEATABLE READ/SERIALIZABLE semantics and never raises SQLSTATE 40001.';
}

/**
 * Writes one machine-readable observation for #97 when explicitly requested.
 *
 * The durable assertion remains quiet in normal local and CI runs. An investigator can set
 * VERDICT_CONCURRENCY_REPORT_PATH to retain repeated real-engine measurements without copying a
 * lossy test-runner transcript. The parent connection has PostgreSQL's configured default; the
 * child payload separately records that every raced session forced SERIALIZABLE before the
 * ready/release barrier.
 *
 * @param  array<int, mixed>  $decoded
 */
function recordPostgresSerializableRateLimitMeasurement(array $decoded, int $admitted): void
{
    $path = getenv('VERDICT_CONCURRENCY_REPORT_PATH');

    if ($path === false || $path === '') {
        return;
    }

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $connection = $manager->connection();
    $defaultIsolation = $connection->selectOne('show default_transaction_isolation');
    $version = $connection->selectOne('show server_version');
    $errors = array_values(array_filter($decoded, fn ($result) => ! is_array($result) || ! ($result['ok'] ?? false)));
    $sqlstates = array_count_values(array_filter(array_map(
        fn ($error) => is_array($error) ? $error['sqlstate'] ?? null : null,
        $errors,
    ), is_string(...)));

    file_put_contents($path, json_encode([
        'scenario' => 'postgresql-serializable-rate-limit',
        'concurrency' => CONCURRENCY_TEST_CONCURRENCY,
        'limit' => CONCURRENCY_TEST_RATE_LIMIT,
        'attempts' => count($decoded),
        'admitted' => $admitted,
        'errors' => count($errors),
        'sqlstates' => $sqlstates,
        'parent_default_isolation' => $defaultIsolation->default_transaction_isolation,
        'raced_session_isolation' => 'serializable',
        'server_version' => $version->server_version,
    ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * @param  array<int, mixed>  $decoded
 */
function assertRaceOutcome(array $decoded, int $winners, int $expectedWinners): void
{
    $errors = array_values(array_filter($decoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false)));

    expect($errors)->toBe([])
        ->and($winners)->toBe($expectedWinners);
}

beforeEach(function (): void {
    if (concurrencyTestDriver() === null) {
        $this->markTestSkipped(concurrencyTestSkipReason());
    }

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

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
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }
});

afterEach(function (): void {
    if (concurrencyTestDriver() === null) {
        return;
    }

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    foreach (['verdict_rate_limit_buckets', 'verdict_execution_claims', 'verdict_approval_receipts'] as $table) {
        $schema->dropIfExists($table);
    }
});

/** @verdict-claim limitation.security-state-retries */
it('admits at most the configured limit when racing DatabaseRateLimitStore::consume() under the connection\'s natural isolation level', function (): void {
    $bucketFingerprint = hash('sha256', random_bytes(16));
    $at = (new DateTimeImmutable)->format(DATE_ATOM);

    $payloads = array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => concurrencyTestConnectionConfig(),
        'bucket_fingerprint' => $bucketFingerprint,
        'limit' => CONCURRENCY_TEST_RATE_LIMIT,
        'window_seconds' => 60,
        'at' => $at,
    ]);

    $results = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/rate-limit-consume.php', $payloads);
    $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);
    $admitted = count(array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && $d['allowed']));

    assertRaceOutcome($decoded, $admitted, CONCURRENCY_TEST_RATE_LIMIT);
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('admits at most one winner when racing DatabaseExecutionClaimStore::claim() under the connection\'s natural isolation level', function (): void {
    $bindingFingerprint = hash('sha256', random_bytes(16));
    $at = (new DateTimeImmutable)->format(DATE_ATOM);

    $payloads = array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => concurrencyTestConnectionConfig(),
        'binding_fingerprint' => $bindingFingerprint,
        'at' => $at,
    ]);

    $results = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/execution-claim-claim.php', $payloads);
    $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);
    $winners = count(array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['admitted'] ?? false)));

    assertRaceOutcome($decoded, $winners, 1);
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('issues at most one receipt when racing DatabaseApprovalReceiptStore::issue() under the connection\'s natural isolation level', function (): void {
    $bindingFingerprint = hash('sha256', random_bytes(16));
    $toolCallId = bin2hex(random_bytes(16));
    $at = (new DateTimeImmutable)->format(DATE_ATOM);

    $payloads = array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => concurrencyTestConnectionConfig(),
        'binding_fingerprint' => $bindingFingerprint,
        'tool_call_id' => $toolCallId,
        'at' => $at,
    ]);

    $results = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/approval-receipt-issue.php', $payloads);
    $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);
    $issuers = count(array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['issued'] ?? false)));

    assertRaceOutcome($decoded, $issuers, 1);
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('resolves PostgreSQL SERIALIZABLE cleanly for all security-state stores at high contention', function (): void {
    if (concurrencyTestDriver() !== 'pgsql') {
        $this->markTestSkipped('This test is specific to PostgreSQL SERIALIZABLE; MySQL/MariaDB are covered by the tests above at their natural REPEATABLE READ default.');
    }

    $connectionConfig = concurrencyTestConnectionConfig();
    $at = (new DateTimeImmutable)->format(DATE_ATOM);

    // Execution claims and approval receipts: confirmed by #37/#86 to resolve cleanly under
    // SERIALIZABLE even at 20-way contention. These are enforced, not just observed.
    $claimResults = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/execution-claim-claim.php', array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => $connectionConfig,
        'binding_fingerprint' => hash('sha256', random_bytes(16)),
        'at' => $at,
        'force_serializable' => true,
    ]));
    $claimDecoded = array_map(fn ($r) => json_decode($r['stdout'], true), $claimResults);
    $claimErrors = array_values(array_filter($claimDecoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false)));
    $claimWinners = count(array_filter($claimDecoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['admitted'] ?? false)));

    expect($claimErrors)->toBe([])
        ->and($claimWinners)->toBe(1);

    $approvalResults = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/approval-receipt-issue.php', array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => $connectionConfig,
        'binding_fingerprint' => hash('sha256', random_bytes(16)),
        'tool_call_id' => bin2hex(random_bytes(16)),
        'at' => $at,
        'force_serializable' => true,
    ]));
    $approvalDecoded = array_map(fn ($r) => json_decode($r['stdout'], true), $approvalResults);
    $approvalErrors = array_values(array_filter($approvalDecoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false)));
    $approvalIssuers = count(array_filter($approvalDecoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['issued'] ?? false)));

    expect($approvalErrors)->toBe([])
        ->and($approvalIssuers)->toBe(1);

    // Rate limits: #97 measured that three bounded retries with increasing randomized delay resolve
    // the last PostgreSQL SERIALIZABLE conflict path. This strict assertion retains the underlying
    // policy invariant (exactly the configured limit admitted) and proves callers do not receive a
    // leaked SQLSTATE 40001 under this deliberately synchronized 20-way race.
    $rateLimitResults = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/rate-limit-consume.php', array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => $connectionConfig,
        'bucket_fingerprint' => hash('sha256', random_bytes(16)),
        'limit' => CONCURRENCY_TEST_RATE_LIMIT,
        'window_seconds' => 60,
        'at' => $at,
        'force_serializable' => true,
    ]));
    $rateLimitDecoded = array_map(fn ($r) => json_decode($r['stdout'], true), $rateLimitResults);
    $rateLimitAdmitted = count(array_filter($rateLimitDecoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && $d['allowed']));
    $rateLimitErrors = array_values(array_filter($rateLimitDecoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false)));

    recordPostgresSerializableRateLimitMeasurement($rateLimitDecoded, $rateLimitAdmitted);

    expect($rateLimitErrors)->toBe([])
        ->and($rateLimitAdmitted)->toBe(CONCURRENCY_TEST_RATE_LIMIT);
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());
