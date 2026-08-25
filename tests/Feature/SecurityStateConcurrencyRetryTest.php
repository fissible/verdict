<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimOutcome;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;
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

/** @return array<string, mixed> */
function concurrencyTestConnectionConfig(): array
{
    $connection = app(DatabaseManager::class);

    return config("database.connections.{$connection->getDefaultConnection()}");
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

function concurrencyApprovalReceipt(string $bindingFingerprint, string $toolCallId, string $at): ApprovalReceipt
{
    $createdAt = new DateTimeImmutable($at);

    return new ApprovalReceipt(
        id: bin2hex(random_bytes(16)),
        toolCallId: $toolCallId,
        capability: 'concurrency-test.approval',
        bindingFingerprint: $bindingFingerprint,
        provenance: null,
        approvalContext: null,
        status: ApprovalReceiptStatus::Pending,
        reason: null,
        expiresAt: $createdAt->modify('+1 hour'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $createdAt,
        updatedAt: $createdAt,
    );
}

function concurrencyApprovalStore(): DatabaseApprovalReceiptStore
{
    return new DatabaseApprovalReceiptStore(app(DatabaseManager::class)->connection());
}

function concurrencyExecutionClaimStore(): DatabaseExecutionClaimStore
{
    return new DatabaseExecutionClaimStore(app(DatabaseManager::class)->connection());
}

/** Seed a claimed or indeterminate execution claim for a transition race. */
function concurrencySeededExecutionClaim(string $at, bool $indeterminate = false): ExecutionClaim
{
    $store = concurrencyExecutionClaimStore();
    $claimedAt = new DateTimeImmutable($at);
    $claim = new ExecutionClaim(
        id: bin2hex(random_bytes(16)),
        capability: 'concurrency-test.execution-claim',
        policy: 'concurrency-test-policy',
        bindingFingerprint: hash('sha256', random_bytes(16)),
        status: ExecutionClaimStatus::Claimed,
        attemptCount: 1,
        claimedAt: $claimedAt,
        completedAt: null,
        indeterminateAt: null,
        releasedAt: null,
        resolvedBy: null,
        resolutionReason: null,
        createdAt: $claimedAt,
        updatedAt: $claimedAt,
    );

    expect($store->claim($claim)->outcome)->toBe(ExecutionClaimOutcome::Claimed);

    if ($indeterminate) {
        expect($store->markIndeterminate($claim->id, $claimedAt)->outcome)->toBe(ExecutionClaimOutcome::Indeterminate);
    }

    return $claim;
}

/**
 * Seed a receipt for the transition races to contend over, from the parent process.
 *
 * The seeding calls are asserted rather than assumed: a race that silently started from the wrong
 * receipt state would report a clean "exactly one winner" for a contest that never happened.
 */
function concurrencySeededReceipt(string $at, bool $approved): ApprovalReceipt
{
    $store = concurrencyApprovalStore();
    $receipt = concurrencyApprovalReceipt(hash('sha256', random_bytes(16)), bin2hex(random_bytes(16)), $at);

    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);

    if ($approved) {
        expect($store->approve($receipt->id, $receipt->toolCallId, 'concurrency-test:approver', new DateTimeImmutable($at))->outcome)
            ->toBe(ApprovalOutcome::Approved);
    }

    return $receipt;
}

beforeEach(function (): void {
    if (concurrencyTestDriver() === null) {
        $this->markTestSkipped(concurrencyTestSkipReason());
    }

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    foreach ([verdictTable('rate_limits'), verdictTable('execution_claims'), verdictTable('approvals')] as $table) {
        $schema->dropIfExists($table);
    }

    foreach (
        [
            'create_verdict_rate_limit_buckets_table.php.stub',
            'create_verdict_execution_claims_table.php.stub',
            'create_verdict_approval_receipts_table.php.stub',
            'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
            'add_approval_context_to_verdict_approval_receipts_table.php.stub',
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

    foreach ([verdictTable('rate_limits'), verdictTable('execution_claims'), verdictTable('approvals')] as $table) {
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

/** @verdict-claim limitation.security-state-retries */
it('returns ordinary outcomes when real database connections race every execution-claim transition', function (): void {
    $at = (new DateTimeImmutable)->format(DATE_ATOM);

    foreach ([
        'complete' => [false, ExecutionClaimOutcome::Completed, ExecutionClaimStatus::Completed],
        'mark_indeterminate' => [false, ExecutionClaimOutcome::Indeterminate, ExecutionClaimStatus::Indeterminate],
        'resolve_retryable' => [true, ExecutionClaimOutcome::Released, ExecutionClaimStatus::Released],
    ] as $action => [$indeterminate, $winner, $status]) {
        $claim = concurrencySeededExecutionClaim($at, $indeterminate);
        $results = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/execution-claim-transition.php', array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
            'connection' => concurrencyTestConnectionConfig(),
            'action' => $action,
            'claim_id' => $claim->id,
            'at' => $at,
        ]));
        $decoded = array_map(fn ($result) => json_decode($result['stdout'], true), $results);
        $winners = count(array_filter($decoded, fn ($result) => is_array($result) && ($result['ok'] ?? false) && ($result['outcome'] ?? null) === $winner->value));
        $losers = array_filter($decoded, fn ($result) => is_array($result) && ($result['ok'] ?? false) && ($result['outcome'] ?? null) === ExecutionClaimOutcome::InvalidState->value);

        assertRaceOutcome($decoded, $winners, 1);

        expect($losers)->toHaveCount(CONCURRENCY_TEST_CONCURRENCY - 1)
            ->and(concurrencyExecutionClaimStore()->find($claim->id)?->status)->toBe($status);
    }
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

it('consumes an approved receipt exactly once when real database connections race', function (): void {
    $at = (new DateTimeImmutable)->format(DATE_ATOM);
    $receipt = concurrencySeededReceipt($at, approved: true);
    $store = concurrencyApprovalStore();

    $results = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/approval-receipt-transition.php', array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => concurrencyTestConnectionConfig(),
        'action' => 'consume',
        'tool_call_id' => $receipt->toolCallId,
        'binding_fingerprint' => $receipt->bindingFingerprint,
        'at' => $at,
    ]));
    $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);
    $consumed = count(array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['outcome'] ?? null) === ApprovalOutcome::Consumed->value));

    assertRaceOutcome($decoded, $consumed, 1);

    expect($store->findForToolCall($receipt->toolCallId)?->status)->toBe(ApprovalReceiptStatus::Consumed);
})->skip(fn (): bool => concurrencyTestDriver() === null, concurrencyTestSkipReason());

it('keeps one terminal decision when approve and reject race on separate real database connections', function (): void {
    $at = (new DateTimeImmutable)->format(DATE_ATOM);
    $receipt = concurrencySeededReceipt($at, approved: false);
    $store = concurrencyApprovalStore();
    $payloads = [];

    foreach (range(0, CONCURRENCY_TEST_CONCURRENCY - 1) as $index) {
        $payloads[] = [
            'connection' => concurrencyTestConnectionConfig(),
            'action' => $index % 2 === 0 ? 'approve' : 'reject',
            'receipt_id' => $receipt->id,
            'tool_call_id' => $receipt->toolCallId,
            'decided_by' => "concurrency-test:{$index}",
            'at' => $at,
        ];
    }

    $results = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/approval-receipt-transition.php', $payloads);
    $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);
    $decisions = count(array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && in_array($d['outcome'] ?? null, [ApprovalOutcome::Approved->value, ApprovalOutcome::Rejected->value], true)));

    assertRaceOutcome($decoded, $decisions, 1);

    expect($store->findForToolCall($receipt->toolCallId)?->status)
        ->toBeIn([ApprovalReceiptStatus::Approved, ApprovalReceiptStatus::Rejected]);
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

    // Claim admission takes a binding-fingerprint lock, while its post-admission transitions lock
    // one primary-key row and mutate status. A clean SERIALIZABLE admission race is therefore not
    // evidence for complete(), markIndeterminate(), or resolve(): PostgreSQL reports the latter's
    // concurrent update as SQLSTATE 40001. Race each shape directly so an unguarded bare
    // transaction leaks the conflict instead of this test merely observing blocked losers.
    foreach ([
        'complete' => [false, ExecutionClaimOutcome::Completed, ExecutionClaimStatus::Completed],
        'mark_indeterminate' => [false, ExecutionClaimOutcome::Indeterminate, ExecutionClaimStatus::Indeterminate],
        'resolve_retryable' => [true, ExecutionClaimOutcome::Released, ExecutionClaimStatus::Released],
    ] as $action => [$indeterminate, $winner, $status]) {
        $claim = concurrencySeededExecutionClaim($at, $indeterminate);
        $transitionResults = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/execution-claim-transition.php', array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
            'connection' => $connectionConfig,
            'action' => $action,
            'claim_id' => $claim->id,
            'at' => $at,
            'force_serializable' => true,
        ]));
        $transitionDecoded = array_map(fn ($result) => json_decode($result['stdout'], true), $transitionResults);
        $transitionErrors = array_values(array_filter($transitionDecoded, fn ($result) => ! is_array($result) || ! ($result['ok'] ?? false)));
        $transitionWinners = count(array_filter($transitionDecoded, fn ($result) => is_array($result) && ($result['ok'] ?? false) && ($result['outcome'] ?? null) === $winner->value));
        $transitionLosers = array_filter($transitionDecoded, fn ($result) => is_array($result) && ($result['ok'] ?? false) && ($result['outcome'] ?? null) === ExecutionClaimOutcome::InvalidState->value);

        expect($transitionErrors)->toBe([])
            ->and($transitionWinners)->toBe(1)
            ->and($transitionLosers)->toHaveCount(CONCURRENCY_TEST_CONCURRENCY - 1)
            ->and(concurrencyExecutionClaimStore()->find($claim->id)?->status)->toBe($status);
    }

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

    // Approval transitions, raced directly rather than inferred from issue(). The clean issue()
    // result above is not evidence for them: consume() takes its lock through a different read
    // (`tool_call_id` + `binding_fingerprint`, not the three-column unique key) and then mutates
    // `status`, which is a different predicate-lock shape under SSI. This is where a SERIALIZABLE
    // 40001 would originate if one does, so it is measured, not assumed — the reasoning ADR 0018
    // already applied once to issue()'s non-reproduction.
    $consumeReceipt = concurrencySeededReceipt($at, approved: true);
    $consumeResults = ConcurrencyHarness::run(__DIR__.'/../Support/concurrency-children/approval-receipt-transition.php', array_fill(0, CONCURRENCY_TEST_CONCURRENCY, [
        'connection' => $connectionConfig,
        'action' => 'consume',
        'tool_call_id' => $consumeReceipt->toolCallId,
        'binding_fingerprint' => $consumeReceipt->bindingFingerprint,
        'at' => $at,
        'force_serializable' => true,
    ]));
    $consumeDecoded = array_map(fn ($r) => json_decode($r['stdout'], true), $consumeResults);
    $consumeErrors = array_values(array_filter($consumeDecoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false)));
    $consumers = count(array_filter($consumeDecoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['outcome'] ?? null) === ApprovalOutcome::Consumed->value));

    expect($consumeErrors)->toBe([])
        ->and($consumers)->toBe(1);

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
