<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Support\ApproverSummary;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;

// ADR 0035 §6 — the durable review-request store. Mirrors DatabaseApprovalReceiptStore: every mutation runs
// inside a SecurityStateTransaction (atomic, and refused inside an outer transaction on the store connection),
// timestamps hydrate as UTC, and the JSON-serialized approval_context/provenance/approver_summary degrade to
// "never captured" on an un-migrated or corrupt column rather than hard-failing. It is id-addressed: decisions
// key on the request id, execution-side checks on (capability, binding_fingerprint), unique per that binding.

function reviewTable(): string
{
    $name = config('verdict.reviews.table', 'verdict_review_requests');

    return is_string($name) ? $name : 'verdict_review_requests';
}

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(reviewTable());
    $schema->create(reviewTable(), function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('capability');
        $table->char('binding_fingerprint', 64);
        $table->string('status', 24);
        $table->text('reason')->nullable();
        $table->timestamp('expires_at');
        $table->string('resolved_by')->nullable();
        $table->timestamp('resolved_at')->nullable();
        $table->timestamp('consumed_at')->nullable();
        $table->text('provenance')->nullable();
        $table->text('approval_context')->nullable();
        $table->text('approver_summary')->nullable();
        $table->timestamps();
        $table->unique(['capability', 'binding_fingerprint'], 'verdict_review_requests_binding_unique');
        $table->index(['status', 'expires_at']);
    });
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(reviewTable());
});

function databaseReviewStore(): DatabaseReviewRequestStore
{
    return new DatabaseReviewRequestStore(app(DatabaseManager::class)->connection());
}

function databaseReviewRequest(
    string $id = 'rev_00000000000000000000000000000000',
    string $fingerprint = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
    string $capability = 'orders.cancel',
    ?array $approvalContext = ['tenant_id' => 'store-1'],
    ?ProposalProvenance $provenance = null,
    ?ApproverSummary $approverSummary = null,
    string $created = '2026-08-01 12:00:00',
    string $expires = '2026-08-01 12:15:00',
): ReviewRequest {
    return ReviewRequest::pending(
        id: $id,
        capability: $capability,
        bindingFingerprint: $fingerprint,
        approvalContext: $approvalContext,
        createdAt: new DateTimeImmutable($created, new DateTimeZone('UTC')),
        expiresAt: new DateTimeImmutable($expires, new DateTimeZone('UTC')),
        reason: 'A human must review this cancellation.',
        provenance: $provenance,
        approverSummary: $approverSummary,
    );
}

function reviewClockAt(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('UTC'));
}

// ── lifecycle & atomicity ────────────────────────────────────────────────────────────────────────

it('advances a database request through pending, approved, and consumed', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest());

    $store->approve('rev_00000000000000000000000000000000', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));
    $store->consume('orders.cancel', str_repeat('f', 64), reviewClockAt('2026-08-01 12:10:00'));

    $stored = $store->find('rev_00000000000000000000000000000000');
    expect($stored?->status)->toBe(ReviewStatus::Consumed)
        ->and($stored?->resolvedBy)->toBe('reviewer-7')
        ->and($stored?->resolvedAt)->toEqual(reviewClockAt('2026-08-01 12:05:00'))
        ->and($stored?->consumedAt)->toEqual(reviewClockAt('2026-08-01 12:10:00'));
});

// ── issue: idempotent per (capability, binding) ──────────────────────────────────────────────────

it('returns the existing request when the same binding is re-issued while pending', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a'));

    $transition = $store->issue(databaseReviewRequest(id: 'rev_b')); // same capability+binding

    expect($transition->outcome)->toBe(ReviewOutcome::Existing)
        ->and($transition->request?->id)->toBe('rev_a')
        ->and($store->find('rev_b'))->toBeNull();
});

it('reports Expired when the existing request has lapsed, and InvalidState once it is terminal', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a', expires: '2026-08-01 12:15:00'));

    // Re-issue past the existing expiry (the proposed request's createdAt is the "now").
    $expired = $store->issue(databaseReviewRequest(id: 'rev_b', created: '2026-08-01 12:30:00', expires: '2026-08-01 12:45:00'));
    expect($expired->outcome)->toBe(ReviewOutcome::Expired);

    // Reject it, then re-issue the same binding within its life → InvalidState (stays refused).
    $store->reject('rev_a', 'reviewer-9', reviewClockAt('2026-08-01 12:05:00'));
    $invalid = $store->issue(databaseReviewRequest(id: 'rev_c'));
    expect($invalid->outcome)->toBe(ReviewOutcome::InvalidState);
});

it('returns the existing request when the same binding is re-issued while approved', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a'));
    $store->approve('rev_a', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));

    $transition = $store->issue(databaseReviewRequest(id: 'rev_b'));

    expect($transition->outcome)->toBe(ReviewOutcome::Existing)
        ->and($transition->request?->id)->toBe('rev_a');
});

it('refuses to reissue a binding whose request was consumed', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a'));
    $store->approve('rev_a', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));
    $store->consume('orders.cancel', str_repeat('f', 64), reviewClockAt('2026-08-01 12:10:00'));

    expect($store->issue(databaseReviewRequest(id: 'rev_b'))->outcome)->toBe(ReviewOutcome::InvalidState);
});

it('treats a different binding as a new request', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a', fingerprint: str_repeat('a', 64)));

    $transition = $store->issue(databaseReviewRequest(id: 'rev_b', fingerprint: str_repeat('b', 64)));

    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($store->find('rev_a'))->not->toBeNull()
        ->and($store->find('rev_b'))->not->toBeNull();
});

it('never overwrites an existing id when a different binding reuses it', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a', fingerprint: str_repeat('a', 64)));

    $collision = $store->issue(databaseReviewRequest(id: 'rev_a', fingerprint: str_repeat('b', 64)));

    expect($collision->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($store->find('rev_a')?->bindingFingerprint)->toBe(str_repeat('a', 64));
});

// ── decisions (by id) and execution-side checks (by capability + binding) ─────────────────────────

it('reports NotFound approving an unknown request', function (): void {
    expect(databaseReviewStore()->approve('nope', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'))->outcome)
        ->toBe(ReviewOutcome::NotFound);
});

it('refuses to approve a request that is not pending', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a'));
    $store->approve('rev_a', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));

    expect($store->approve('rev_a', 'reviewer-8', reviewClockAt('2026-08-01 12:06:00'))->outcome)
        ->toBe(ReviewOutcome::InvalidState)
        ->and($store->find('rev_a')?->resolvedBy)->toBe('reviewer-7');
});

it('checks expiry before lifecycle state, so an expired-and-terminal request reports Expired', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a', expires: '2026-08-01 12:15:00'));
    $store->approve('rev_a', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));
    $store->consume('orders.cancel', str_repeat('f', 64), reviewClockAt('2026-08-01 12:10:00'));

    // At the expiry instant the consumed request refuses as Expired (expiry precedes the terminal check).
    expect($store->approve('rev_a', 'reviewer-7', reviewClockAt('2026-08-01 12:15:00'))->outcome)
        ->toBe(ReviewOutcome::Expired);
});

it('validates an approved request without mutating it, and refuses a not-yet-approved one', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a'));

    expect($store->validate('orders.cancel', str_repeat('f', 64), reviewClockAt('2026-08-01 12:05:00'))->outcome)
        ->toBe(ReviewOutcome::InvalidState);

    $store->approve('rev_a', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));

    expect($store->validate('orders.cancel', str_repeat('f', 64), reviewClockAt('2026-08-01 12:06:00'))->outcome)
        ->toBe(ReviewOutcome::Approved)
        ->and($store->find('rev_a')?->status)->toBe(ReviewStatus::Approved); // non-mutating
});

it('refuses to reject an approved request and to validate a rejected or consumed one', function (): void {
    $store = databaseReviewStore();

    // reject on an approved request → InvalidState
    $store->issue(databaseReviewRequest(id: 'rev_app', fingerprint: str_repeat('1', 64)));
    $store->approve('rev_app', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));
    expect($store->reject('rev_app', 'reviewer-9', reviewClockAt('2026-08-01 12:06:00'))->outcome)
        ->toBe(ReviewOutcome::InvalidState);

    // validate a rejected request → InvalidState
    $store->issue(databaseReviewRequest(id: 'rev_rej', fingerprint: str_repeat('2', 64)));
    $store->reject('rev_rej', 'reviewer-9', reviewClockAt('2026-08-01 12:05:00'));
    expect($store->validate('orders.cancel', str_repeat('2', 64), reviewClockAt('2026-08-01 12:06:00'))->outcome)
        ->toBe(ReviewOutcome::InvalidState);

    // validate a consumed request → InvalidState
    $store->issue(databaseReviewRequest(id: 'rev_con', fingerprint: str_repeat('3', 64)));
    $store->approve('rev_con', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));
    $store->consume('orders.cancel', str_repeat('3', 64), reviewClockAt('2026-08-01 12:07:00'));
    expect($store->validate('orders.cancel', str_repeat('3', 64), reviewClockAt('2026-08-01 12:08:00'))->outcome)
        ->toBe(ReviewOutcome::InvalidState);
});

it('reports NotFound on the wrong capability for a matching binding', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a'));
    $store->approve('rev_a', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));

    expect($store->validate('orders.refund', str_repeat('f', 64), reviewClockAt('2026-08-01 12:06:00'))->outcome)
        ->toBe(ReviewOutcome::NotFound)
        ->and($store->consume('orders.refund', str_repeat('f', 64), reviewClockAt('2026-08-01 12:06:00'))->outcome)
        ->toBe(ReviewOutcome::NotFound);
});

it('admits a consumption only once, leaving the consumed row untouched by the second attempt', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a'));
    $store->approve('rev_a', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));
    $store->consume('orders.cancel', str_repeat('f', 64), reviewClockAt('2026-08-01 12:10:00'));

    $second = $store->consume('orders.cancel', str_repeat('f', 64), reviewClockAt('2026-08-01 12:11:00'));
    $stored = $store->find('rev_a');

    expect($second->outcome)->toBe(ReviewOutcome::InvalidState)
        // The second consume neither re-stamps consumed_at nor moves the row off Consumed.
        ->and($stored?->status)->toBe(ReviewStatus::Consumed)
        ->and($stored?->consumedAt)->toEqual(reviewClockAt('2026-08-01 12:10:00'))
        ->and($stored?->resolvedBy)->toBe('reviewer-7');
});

it('rejects a pending request, stamping the resolving actor', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a'));

    $transition = $store->reject('rev_a', 'reviewer-9', reviewClockAt('2026-08-01 12:05:00'));
    $stored = $store->find('rev_a');

    expect($transition->outcome)->toBe(ReviewOutcome::Rejected)
        ->and($stored?->status)->toBe(ReviewStatus::Rejected)
        ->and($stored?->resolvedBy)->toBe('reviewer-9')
        ->and($stored?->resolvedAt)->toEqual(reviewClockAt('2026-08-01 12:05:00'));
});

it('treats the expiry instant as expired on validate and consume (>= boundary)', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a', expires: '2026-08-01 12:15:00'));
    $store->approve('rev_a', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));

    expect($store->validate('orders.cancel', str_repeat('f', 64), reviewClockAt('2026-08-01 12:15:00'))->outcome)
        ->toBe(ReviewOutcome::Expired)
        ->and($store->consume('orders.cancel', str_repeat('f', 64), reviewClockAt('2026-08-01 12:15:00'))->outcome)
        ->toBe(ReviewOutcome::Expired)
        ->and($store->find('rev_a')?->status)->toBe(ReviewStatus::Approved); // still Approved, not consumed
});

it('keeps the same fingerprint under two capabilities as two distinct rows', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_cancel', capability: 'orders.cancel', fingerprint: str_repeat('9', 64)));
    $store->issue(databaseReviewRequest(id: 'rev_refund', capability: 'orders.refund', fingerprint: str_repeat('9', 64)));

    expect($store->find('rev_cancel')?->capability)->toBe('orders.cancel')
        ->and($store->find('rev_refund')?->capability)->toBe('orders.refund');
});

it('refuses to consume an approved-but-expired request, leaving it Approved', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_a', expires: '2026-08-01 12:15:00'));
    $store->approve('rev_a', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));

    expect($store->consume('orders.cancel', str_repeat('f', 64), reviewClockAt('2026-08-01 12:30:00'))->outcome)
        ->toBe(ReviewOutcome::Expired)
        ->and($store->find('rev_a')?->status)->toBe(ReviewStatus::Approved);
});

// ── DB-specifics: UTC, outer-transaction guard ────────────────────────────────────────────────────

it('hydrates request timestamps as UTC regardless of the application timezone', function (): void {
    $previous = date_default_timezone_get();

    try {
        date_default_timezone_set('America/Los_Angeles');
        $store = databaseReviewStore();
        $request = databaseReviewRequest();
        $store->issue($request);
        $store->approve($request->id, 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));
        $store->consume('orders.cancel', $request->bindingFingerprint, reviewClockAt('2026-08-01 12:10:00'));

        $stored = $store->find($request->id);
        // Every hydrated timestamp is UTC and equals what was written, whatever the process timezone.
        expect($stored?->createdAt->getTimezone()->getName())->toBe('UTC')
            ->and($stored?->expiresAt->getTimezone()->getName())->toBe('UTC')
            ->and($stored?->resolvedAt->getTimezone()->getName())->toBe('UTC')
            ->and($stored?->consumedAt->getTimezone()->getName())->toBe('UTC')
            ->and($stored?->createdAt->getTimestamp())->toBe($request->createdAt->getTimestamp())
            ->and($stored?->expiresAt->getTimestamp())->toBe($request->expiresAt->getTimestamp())
            ->and($stored?->resolvedAt->getTimestamp())->toBe(reviewClockAt('2026-08-01 12:05:00')->getTimestamp())
            ->and($stored?->consumedAt->getTimestamp())->toBe(reviewClockAt('2026-08-01 12:10:00')->getTimestamp());
    } finally {
        date_default_timezone_set($previous);
    }
});

it('rejects every request mutation inside an outer transaction on the store connection', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $store = new DatabaseReviewRequestStore($connection);
    $request = databaseReviewRequest();
    $at = reviewClockAt('2026-08-01 12:05:00');
    $store->issue($request);

    $mutations = [
        'issue a review request' => fn () => $store->issue(databaseReviewRequest(id: 'rev_other', fingerprint: str_repeat('b', 64))),
        'approve a review request' => fn () => $store->approve($request->id, 'reviewer-7', $at),
        'reject a review request' => fn () => $store->reject($request->id, 'reviewer-9', $at),
        'consume a review request' => fn () => $store->consume('orders.cancel', $request->bindingFingerprint, $at),
    ];
    $outcomes = [];

    $connection->beginTransaction();
    try {
        foreach ($mutations as $operation => $mutate) {
            try {
                $mutate();
                $outcomes[$operation] = 'completed';
            } catch (Throwable $error) {
                $outcomes[$operation] = $error instanceof UnsafeOuterTransaction
                    && str_contains($error->getMessage(), $operation) ? 'refused' : $error::class;
            }
        }

        // Inside the still-open outer transaction, no refused mutation wrote durable state: exactly the one
        // seeded row exists, still Pending. (Asserting after rollback could not tell a refusal from a rollback.)
        $insideRow = $connection->table(reviewTable())->where('id', $request->id)->first();
        expect($connection->table(reviewTable())->count())->toBe(1)
            ->and($insideRow->status)->toBe(ReviewStatus::Pending->value)
            ->and($insideRow->resolved_by)->toBeNull()
            ->and($insideRow->consumed_at)->toBeNull();
    } finally {
        $connection->rollBack();
    }

    expect($outcomes)->toBe([
        'issue a review request' => 'refused',
        'approve a review request' => 'refused',
        'reject a review request' => 'refused',
        'consume a review request' => 'refused',
    ])
        ->and($connection->transactionLevel())->toBe(0)
        ->and($store->find($request->id)?->status)->toBe(ReviewStatus::Pending);
});

// ── serialization round-trips + schema degradation ───────────────────────────────────────────────

it('round-trips the approval context, keeping never-captured distinct from captured-empty', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(id: 'rev_null', fingerprint: str_repeat('1', 64), approvalContext: null));
    $store->issue(databaseReviewRequest(id: 'rev_empty', fingerprint: str_repeat('2', 64), approvalContext: []));
    $store->issue(databaseReviewRequest(id: 'rev_full', fingerprint: str_repeat('3', 64), approvalContext: ['tenant_id' => 'store-1']));

    expect($store->find('rev_null')?->approvalContext)->toBeNull()
        ->and($store->find('rev_empty')?->approvalContext)->toBe([])
        ->and($store->find('rev_full')?->approvalContext)->toBe(['tenant_id' => 'store-1']);
});

it('hydrates corrupt or malformed JSON columns as never captured rather than erroring', function (): void {
    // Every JSON column degrades to null on a scalar OR structurally-broken value — a find() must never throw.
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(
        id: 'rev_a',
        provenance: ProposalProvenance::declared(sources: [], undescribedSourceCount: 1),
        approverSummary: new ApproverSummary(content: 'x', fingerprint: 'fp'),
    ));
    app(DatabaseManager::class)->connection()->table(reviewTable())->where('id', 'rev_a')->update([
        'approval_context' => '"not-an-array"',
        'provenance' => '{broken',
        'approver_summary' => '{broken',
    ]);

    $stored = $store->find('rev_a');
    expect($stored)->not->toBeNull()
        ->and($stored?->approvalContext)->toBeNull()
        ->and($stored?->provenance)->toBeNull()
        ->and($stored?->approverSummary)->toBeNull();
});

it('issues and decides requests when the approver_summary column has not been migrated yet', function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(reviewTable());
    $schema->create(reviewTable(), function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('capability');
        $table->char('binding_fingerprint', 64);
        $table->string('status', 24);
        $table->text('reason')->nullable();
        $table->timestamp('expires_at');
        $table->string('resolved_by')->nullable();
        $table->timestamp('resolved_at')->nullable();
        $table->timestamp('consumed_at')->nullable();
        $table->text('provenance')->nullable();
        $table->text('approval_context')->nullable();
        $table->timestamps();
        $table->unique(['capability', 'binding_fingerprint'], 'verdict_review_requests_binding_unique');
    });

    $store = databaseReviewStore();
    $summary = new ApproverSummary(content: 'Cancel order 7001.', fingerprint: 'summary-fp-1');

    // Guided upgrade: the summary degrades to never-captured rather than hard-failing issuance.
    expect($store->issue(databaseReviewRequest(approverSummary: $summary))->outcome)->toBe(ReviewOutcome::Issued)
        ->and($store->find('rev_00000000000000000000000000000000')?->approverSummary)->toBeNull();
});

it('round-trips provenance and the approver summary through the database', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(
        provenance: ProposalProvenance::declared(sources: [], undescribedSourceCount: 2, withheldSourceCount: 1),
        approverSummary: new ApproverSummary(content: 'Cancel order 7001.', fingerprint: 'summary-fp-1'),
    ));

    $stored = $store->find('rev_00000000000000000000000000000000');
    expect($stored?->provenance?->toArray())
        ->toEqual(ProposalProvenance::declared(sources: [], undescribedSourceCount: 2, withheldSourceCount: 1)->toArray())
        ->and($stored?->approverSummary?->content)->toBe('Cancel order 7001.')
        ->and($stored?->approverSummary?->fingerprint)->toBe('summary-fp-1');

    // The review store — unlike the fingerprint-only evidence — persists the summary CONTENT, because the
    // reviewer reads it out of band. The raw column is JSON {content, fingerprint}.
    $raw = app(DatabaseManager::class)->connection()->table(reviewTable())
        ->where('id', 'rev_00000000000000000000000000000000')->value('approver_summary');
    expect(json_decode((string) $raw, true))->toBe(['content' => 'Cancel order 7001.', 'fingerprint' => 'summary-fp-1']);
});

it('creates the durable table from the published migration with the binding unique + status index', function (): void {
    // The store harness builds the table inline; this proves the SHIPPED migration matches — the constraint
    // and index the store's idempotency and enumeration rely on actually exist.
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(reviewTable());

    (require __DIR__.'/../../database/migrations/create_verdict_review_requests_table.php.stub')->up();

    expect($schema->hasTable(reviewTable()))->toBeTrue()
        ->and($schema->hasColumn(reviewTable(), 'approver_summary'))->toBeTrue()
        ->and($schema->hasColumn(reviewTable(), 'resolved_by'))->toBeTrue();

    // The (status, expires_at) index the pending-enumeration reader relies on exists in the shipped migration.
    $hasStatusIndex = collect($schema->getIndexes(reviewTable()))
        ->contains(fn (array $index): bool => $index['columns'] === ['status', 'expires_at']);
    expect($hasStatusIndex)->toBeTrue();

    // The binding UNIQUE constraint — which the store's one-request-per-binding idempotency relies on — is
    // proven by a raw duplicate insert against the migrated table: the second write must be rejected.
    $connection = app(DatabaseManager::class)->connection();
    $row = fn (string $id): array => [
        'id' => $id, 'capability' => 'orders.cancel', 'binding_fingerprint' => str_repeat('d', 64),
        'status' => 'pending', 'reason' => null, 'expires_at' => '2026-08-01 12:15:00',
        'resolved_by' => null, 'resolved_at' => null, 'consumed_at' => null,
        'provenance' => null, 'approval_context' => null, 'approver_summary' => null,
        'created_at' => '2026-08-01 12:00:00', 'updated_at' => '2026-08-01 12:00:00',
    ];
    $connection->table(reviewTable())->insert($row('rev_first'));

    expect(fn (): mixed => $connection->table(reviewTable())->insert($row('rev_second')))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('reads the configured table name and rolls back cleanly with down()', function (): void {
    // Guards against a stub that hardcodes the default table or ships a broken/no-op down(): up() must create
    // the CONFIGURED table and down() must remove it.
    $table = 'verdict_reviews_custom';
    config()->set('verdict.reviews.table', $table);
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists($table);

    $migration = require __DIR__.'/../../database/migrations/create_verdict_review_requests_table.php.stub';

    $migration->up();
    expect($schema->hasTable($table))->toBeTrue();

    $migration->down();
    expect($schema->hasTable($table))->toBeFalse();
});

it('preserves all immutable material through a transition', function (): void {
    $store = databaseReviewStore();
    $store->issue(databaseReviewRequest(
        approvalContext: ['tenant_id' => 'store-1', 'conversation_id' => 'c-42'],
        approverSummary: new ApproverSummary(content: 'Cancel order 7001.', fingerprint: 'summary-fp-1'),
    ));

    $store->approve('rev_00000000000000000000000000000000', 'reviewer-7', reviewClockAt('2026-08-01 12:05:00'));

    $stored = $store->find('rev_00000000000000000000000000000000');
    expect($stored?->status)->toBe(ReviewStatus::Approved)
        ->and($stored?->capability)->toBe('orders.cancel')
        ->and($stored?->approvalContext)->toBe(['tenant_id' => 'store-1', 'conversation_id' => 'c-42'])
        ->and($stored?->reason)->toBe('A human must review this cancellation.')
        ->and($stored?->approverSummary?->fingerprint)->toBe('summary-fp-1')
        ->and($stored?->createdAt)->toEqual(reviewClockAt('2026-08-01 12:00:00'))
        ->and($stored?->expiresAt)->toEqual(reviewClockAt('2026-08-01 12:15:00'));
});

it('implements the ReviewRequestStore contract', function (): void {
    expect(databaseReviewStore())->toBeInstanceOf(ReviewRequestStore::class);
});
