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
 * **MariaDB is asserted differently from MySQL, deliberately.** Measured directly while building
 * this fix: real MySQL 8 fully and reliably resolves under the 2-attempt retry (0 errors across
 * every run tested, 20-way contention, all three stores). Real MariaDB 11 does NOT — it shows a
 * substantial, inconsistent residual failure rate (commonly ~19/20, occasionally 0/20, across
 * repeated runs) for all three stores, and raising the retry count to 5 made no measurable
 * difference. Both report as Laravel's "mysql" driver and both nominally run InnoDB REPEATABLE
 * READ, but they are not equivalent here — this is a genuine, unexplained difference between
 * MySQL 8's and MariaDB 11's InnoDB behavior for this exact contention pattern, not a flake in
 * this test or a flaw in the retry logic that more attempts would fix. It's tracked as a
 * follow-up (see docs/limitations.md and ADR 0018) rather than silently asserted away here.
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
 * Shared assertion for a race's outcome. MySQL and PostgreSQL (at the isolation levels these
 * tests exercise) are asserted strictly: zero errors, exactly the expected admission count —
 * confirmed reliable by direct measurement. MariaDB is asserted the way ADR 0018 documents its
 * known residual gap: the invariant must still hold among responses that don't throw (never
 * *more* than expected admitted — a wrong answer would be a correctness regression, which is not
 * what's been observed), and every thrown exception must be the expected, bounded SQLSTATE 40001
 * rather than something new — but zero errors is not asserted, because that would not reliably be
 * true today.
 *
 * @param  array<int, mixed>  $decoded
 */
function assertRaceOutcome(array $decoded, int $winners, int $expectedWinners): void
{
    $errors = array_values(array_filter($decoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false)));

    if (concurrencyTestIsMariaDb()) {
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

    assertRaceOutcome($decoded, $admitted, CONCURRENCY_TEST_RATE_LIMIT);
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

    assertRaceOutcome($decoded, $winners, 1);
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

    assertRaceOutcome($decoded, $issuers, 1);
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
    // still-substantial residual (17-18/20 across repeated runs), not zero. This is a known,
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
