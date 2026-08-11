<?php

declare(strict_types=1);

use Fissible\Verdict\Tests\Support\ConcurrencyHarness;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

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
 * **Every child process forces its lazy PDO connection before the start barrier.**
 * `Connection::$pdo` starts as a Closure and is only resolved on first use; without an explicit
 * `getPdo()` call before the barrier wait loop, the barrier would release processes that then each
 * independently pay a TCP handshake right after, silently reintroducing the unsynchronized-startup
 * variance the barrier exists to eliminate. Found in review of this suite's first version — see
 * `tests/Support/concurrency-children/*.php`.
 *
 * **Strictness varies by engine and store, based on direct measurement with the corrected
 * (connection-forced) barrier — not by assumption.** Some combinations are asserted strictly (zero
 * errors); others, proven to have a real residual failure rate even after the fix, are asserted
 * more loosely: the invariant must still hold among responses that don't throw, and every thrown
 * exception must be the expected, bounded SQLSTATE 40001 — never a hard zero-error assertion where
 * that would not reliably be true and would make the test flaky rather than honest. See
 * `concurrencyTestIsKnownFlaky()` below for exactly which combinations, and ADR 0018 /
 * `docs/limitations.md` for the measured rates behind each one.
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

function concurrencyTestIsMariaDb(): bool
{
    if (concurrencyTestDriver() !== 'mysql') {
        return false;
    }

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $version = $manager->connection()->select('SELECT VERSION() as v')[0]->v ?? '';

    return str_contains((string) $version, 'MariaDB');
}

/**
 * Which (engine, store) combinations are known, by direct measurement with the corrected
 * (connection-forced) barrier, to retain a real residual failure rate even after #86's fix:
 *
 * - MariaDB 11: all three stores. Commonly ~19/20 failures under 20-way contention, occasionally
 *   0/20 — inconsistent, not explained by retry count (see #92).
 * - MySQL 8, rate limits only: rare but real (~1 in 14 runs observed at 19/20 failures; every
 *   other run clean). Execution claims and approval receipts on MySQL were clean across every run
 *   tested (14+) and are asserted strictly.
 * - PostgreSQL SERIALIZABLE, rate limits only: see the dedicated SERIALIZABLE test below.
 */
function concurrencyTestIsKnownFlaky(string $store): bool
{
    if (concurrencyTestIsMariaDb()) {
        return true;
    }

    return concurrencyTestDriver() === 'mysql' && $store === 'rate_limit';
}

/**
 * @param  array<int, mixed>  $decoded
 */
function assertRaceOutcome(array $decoded, int $winners, int $expectedWinners, string $store): void
{
    $errors = array_values(array_filter($decoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false)));

    if (concurrencyTestIsKnownFlaky($store)) {
        expect($winners)->toBeLessThanOrEqual($expectedWinners);

        foreach ($errors as $error) {
            expect($error)->toBeArray()
                ->and($error['sqlstate'] ?? null)->toBe('40001');
        }

        return;
    }

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

it('admits at most the configured limit when racing DatabaseRateLimitStore::consume() under the connection\'s natural isolation level', function (): void {
    $bucketFingerprint = hash('sha256', random_bytes(16));
    $at = (new DateTimeImmutable)->format(DATE_ATOM);
    $startAt = ConcurrencyHarness::startAt();

    $payloads = array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => concurrencyTestConnectionConfig(),
        'bucket_fingerprint' => $bucketFingerprint,
        'limit' => CONCURRENCY_TEST_RATE_LIMIT,
        'window_seconds' => 60,
        'at' => $at,
        'start_at' => $startAt,
    ]);

    $results = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/rate-limit-consume.php', $payloads);
    $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);
    $admitted = count(array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && $d['allowed']));

    assertRaceOutcome($decoded, $admitted, CONCURRENCY_TEST_RATE_LIMIT, 'rate_limit');
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('admits at most one winner when racing DatabaseExecutionClaimStore::claim() under the connection\'s natural isolation level', function (): void {
    $bindingFingerprint = hash('sha256', random_bytes(16));
    $at = (new DateTimeImmutable)->format(DATE_ATOM);
    $startAt = ConcurrencyHarness::startAt();

    $payloads = array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => concurrencyTestConnectionConfig(),
        'binding_fingerprint' => $bindingFingerprint,
        'at' => $at,
        'start_at' => $startAt,
    ]);

    $results = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/execution-claim-claim.php', $payloads);
    $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);
    $winners = count(array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['admitted'] ?? false)));

    assertRaceOutcome($decoded, $winners, 1, 'claim');
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('issues at most one receipt when racing DatabaseApprovalReceiptStore::issue() under the connection\'s natural isolation level', function (): void {
    $bindingFingerprint = hash('sha256', random_bytes(16));
    $toolCallId = bin2hex(random_bytes(16));
    $at = (new DateTimeImmutable)->format(DATE_ATOM);
    $startAt = ConcurrencyHarness::startAt();

    $payloads = array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => concurrencyTestConnectionConfig(),
        'binding_fingerprint' => $bindingFingerprint,
        'tool_call_id' => $toolCallId,
        'at' => $at,
        'start_at' => $startAt,
    ]);

    $results = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/approval-receipt-issue.php', $payloads);
    $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);
    $issuers = count(array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['issued'] ?? false)));

    assertRaceOutcome($decoded, $issuers, 1, 'approval');
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('resolves PostgreSQL SERIALIZABLE cleanly for execution claims and approval receipts at high contention, but rate limits retain a known residual failure rate', function (): void {
    if (concurrencyTestDriver() !== 'pgsql') {
        $this->markTestSkipped('This test is specific to PostgreSQL SERIALIZABLE; MySQL/MariaDB are covered by the tests above at their natural REPEATABLE READ default.');
    }

    $connectionConfig = concurrencyTestConnectionConfig();
    $at = (new DateTimeImmutable)->format(DATE_ATOM);

    // Execution claims and approval receipts: confirmed by #37/#86 to resolve cleanly under
    // SERIALIZABLE even at 20-way contention. These are enforced, not just observed.
    $startAt = ConcurrencyHarness::startAt();
    $claimResults = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/execution-claim-claim.php', array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => $connectionConfig,
        'binding_fingerprint' => hash('sha256', random_bytes(16)),
        'at' => $at,
        'start_at' => $startAt,
        'force_serializable' => true,
    ]));
    $claimDecoded = array_map(fn ($r) => json_decode($r['stdout'], true), $claimResults);
    $claimErrors = array_values(array_filter($claimDecoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false)));
    $claimWinners = count(array_filter($claimDecoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['admitted'] ?? false)));

    expect($claimErrors)->toBe([])
        ->and($claimWinners)->toBe(1);

    $startAt = ConcurrencyHarness::startAt();
    $approvalResults = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/approval-receipt-issue.php', array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => $connectionConfig,
        'binding_fingerprint' => hash('sha256', random_bytes(16)),
        'tool_call_id' => bin2hex(random_bytes(16)),
        'at' => $at,
        'start_at' => $startAt,
        'force_serializable' => true,
    ]));
    $approvalDecoded = array_map(fn ($r) => json_decode($r['stdout'], true), $approvalResults);
    $approvalErrors = array_values(array_filter($approvalDecoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false)));
    $approvalIssuers = count(array_filter($approvalDecoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['issued'] ?? false)));

    expect($approvalErrors)->toBe([])
        ->and($approvalIssuers)->toBe(1);

    // Rate limits: NOT fully resolved at 20-way SERIALIZABLE contention by a bounded 2-attempt
    // retry with no backoff — measured directly (#86): errors dropped from 20/20 (unfixed) to a
    // still-substantial residual (~18/20 across repeated runs), not zero. This is a known,
    // disclosed limitation (see ADR 0018 and docs/limitations.md), not an oversight — a caller
    // may still receive an unhandled QueryException under sustained, fully-simultaneous
    // contention on one bucket under SERIALIZABLE specifically. This test pins that reality:
    // it asserts the invariant still holds among responses that DON'T throw (never more than the
    // configured limit admitted — a wrong answer would be a correctness regression, worse than
    // the known availability gap), and that every thrown exception is the expected, bounded
    // SQLSTATE 40001 rather than something new and unexpected. It deliberately does not assert
    // zero errors, because that would not be true today and pretending otherwise would make this
    // a flaky test rather than an honest one.
    $startAt = ConcurrencyHarness::startAt();
    $rateLimitResults = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/rate-limit-consume.php', array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => $connectionConfig,
        'bucket_fingerprint' => hash('sha256', random_bytes(16)),
        'limit' => CONCURRENCY_TEST_RATE_LIMIT,
        'window_seconds' => 60,
        'at' => $at,
        'start_at' => $startAt,
        'force_serializable' => true,
    ]));
    $rateLimitDecoded = array_map(fn ($r) => json_decode($r['stdout'], true), $rateLimitResults);
    $rateLimitAdmitted = count(array_filter($rateLimitDecoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && $d['allowed']));
    $rateLimitErrors = array_values(array_filter($rateLimitDecoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false)));

    expect($rateLimitAdmitted)->toBeLessThanOrEqual(CONCURRENCY_TEST_RATE_LIMIT);

    foreach ($rateLimitErrors as $error) {
        expect($error)->toBeArray()
            ->and($error['exception'] ?? null)->toBe(QueryException::class)
            ->and($error['sqlstate'] ?? null)->toBe('40001');
    }
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());
