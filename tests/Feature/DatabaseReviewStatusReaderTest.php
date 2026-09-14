<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Contracts\ReviewStatusReader;
use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Reviews\DatabaseReviewStatusReader;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\InMemoryReviewStatusReader;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Reviews\ReviewStatusView;
use Fissible\Verdict\Reviews\ReviewTransition;
use Fissible\Verdict\Reviews\StoreBackedReviewStatusReader;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

/**
 * #468 — Verdict shipped DatabaseReviewRequestStore without a reader that can enumerate it.
 * StoreBackedReviewStatusReader::pendingWithin() refuses by design, because the write contract has
 * no enumeration method, and its message tells a store owner to implement one. That instruction was
 * aimed at *custom* stores; applying it to Verdict's own database store made every consumer of a
 * shipped store write read semantics this package defines — verdict-console did exactly that, on
 * ADR 0035 §4's documented order, and carried the result downstream.
 *
 * This reader is the approvals precedent one lane over: DatabaseApprovalStatusReader, whose
 * enumeration is a portable candidate query (persisted status, a context worth matching, contractual
 * order), typed containment applied in PHP on the decoded context, and hydration of only the matches
 * through the store's own row mapper. No backend JSON containment operator and no backend's
 * number/string coercion is involved — #327's portability decision, which this inherits rather than
 * relitigates.
 */
function reviewReaderTable(): string
{
    $name = config('verdict.reviews.table', 'verdict_review_requests');

    return is_string($name) ? $name : 'verdict_review_requests';
}

function createReviewReaderTable(bool $withApprovalContext = true): void
{
    $name = reviewReaderTable();
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists($name);
    $schema->create($name, function (Blueprint $table) use ($withApprovalContext, $name): void {
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

        // Omitted for the un-migrated case: an install that never ran the approval_context
        // migration has no request carrying a context, so enumeration must answer honestly.
        if ($withApprovalContext) {
            $table->text('approval_context')->nullable();
        }

        $table->text('approver_summary')->nullable();
        $table->timestamps();
        // Derived from the table name, exactly as the shipped stub derives it: index names are
        // database-global in SQLite, so a fixed name collides the moment a test renames the table.
        $table->unique(['capability', 'binding_fingerprint'], $name.'_binding_unique');
        $table->index(['status', 'expires_at']);
    });
}

beforeEach(function (): void {
    createReviewReaderTable();
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(reviewReaderTable());
});

function reviewReaderStore(): DatabaseReviewRequestStore
{
    return new DatabaseReviewRequestStore(app(DatabaseManager::class)->connection());
}

function reviewReaderRequest(
    string $id,
    ?array $approvalContext = ['tenant_id' => 'store-1'],
    string $created = '2026-08-01 12:00:00',
    string $expires = '2026-08-01 12:15:00',
    ?string $capability = null,
): ReviewRequest {
    return ReviewRequest::pending(
        id: $id,
        capability: $capability ?? 'orders.cancel',
        // Unique per request: the store's (capability, binding_fingerprint) constraint would
        // otherwise collapse a fixture set into one row and quietly hollow out these tests.
        bindingFingerprint: substr(hash('sha256', $id), 0, 64),
        approvalContext: $approvalContext,
        createdAt: new DateTimeImmutable($created, new DateTimeZone('UTC')),
        expiresAt: new DateTimeImmutable($expires, new DateTimeZone('UTC')),
        reason: 'A human must review this cancellation.',
    );
}

/** @param list<ReviewStatusView> $views @return list<string> */
function reviewReaderIds(array $views): array
{
    return array_map(static fn (ReviewStatusView $view): string => $view->requestId, $views);
}

function reviewReaderAt(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('UTC'));
}

// ── status reads ─────────────────────────────────────────────────────────────────────────────────

it('reads one request status through the store, and reports nothing for an unknown id', function (): void {
    $store = reviewReaderStore();
    $store->issue(reviewReaderRequest('rev_a'));

    $reader = new DatabaseReviewStatusReader($store);

    expect($reader->statusFor('rev_a')?->requestId)->toBe('rev_a')
        ->and($reader->statusFor('rev_a')?->status)->toBe(ReviewStatus::Pending)
        ->and($reader->statusFor('rev_absent'))->toBeNull();
});

// ── enumeration: what matches ────────────────────────────────────────────────────────────────────

it('enumerates pending requests whose context contains every scope pair', function (): void {
    $store = reviewReaderStore();
    $store->issue(reviewReaderRequest('rev_a', ['tenant_id' => 'store-1', 'region' => 'us']));
    $store->issue(reviewReaderRequest('rev_b', ['tenant_id' => 'store-1']));
    $store->issue(reviewReaderRequest('rev_c', ['tenant_id' => 'store-2', 'region' => 'us']));

    $reader = new DatabaseReviewStatusReader($store);

    // Containment, not equality: rev_a carries an extra key and still matches. rev_b lacks a
    // requested key and does not; rev_c disagrees on one.
    expect(reviewReaderIds($reader->pendingWithin(['tenant_id' => 'store-1'])))->toBe(['rev_a', 'rev_b'])
        ->and(reviewReaderIds($reader->pendingWithin(['tenant_id' => 'store-1', 'region' => 'us'])))->toBe(['rev_a'])
        ->and(reviewReaderIds($reader->pendingWithin(['region' => 'us'])))->toBe(['rev_a', 'rev_c']);
});

it('matches a scope value by type, so an integer never matches its string spelling', function (): void {
    $store = reviewReaderStore();
    $store->issue(reviewReaderRequest('rev_int', ['tenant_id' => 42]));
    $store->issue(reviewReaderRequest('rev_str', ['tenant_id' => '42']));

    $reader = new DatabaseReviewStatusReader($store);

    // The portability decision this inherits from #327: containment is decided in PHP on the
    // decoded context, so no backend's JSON operator gets to coerce 42 and '42' together. A
    // database-side containment predicate would answer this differently on MySQL than on SQLite.
    expect(reviewReaderIds($reader->pendingWithin(['tenant_id' => 42])))->toBe(['rev_int'])
        ->and(reviewReaderIds($reader->pendingWithin(['tenant_id' => '42'])))->toBe(['rev_str']);
});

it('never enumerates a request with no context to match', function (): void {
    $store = reviewReaderStore();
    $store->issue(reviewReaderRequest('rev_null', null));
    $store->issue(reviewReaderRequest('rev_empty', []));
    $store->issue(reviewReaderRequest('rev_has', ['tenant_id' => 'store-1']));

    $reader = new DatabaseReviewStatusReader($store);

    // '[]' is a real stored value — a context captured empty — and can never contain a non-empty
    // scope. Both it and NULL are excluded, and the contract says so explicitly.
    expect(reviewReaderIds($reader->pendingWithin(['tenant_id' => 'store-1'])))->toBe(['rev_has']);
});

it('enumerates only requests whose persisted status is pending', function (): void {
    $store = reviewReaderStore();
    $store->issue(reviewReaderRequest('rev_pending'));
    $store->issue(reviewReaderRequest('rev_approved'));
    $store->issue(reviewReaderRequest('rev_rejected'));

    $store->approve('rev_approved', 'reviewer-1', reviewReaderAt('2026-08-01 12:05:00'));
    $store->reject('rev_rejected', 'reviewer-2', reviewReaderAt('2026-08-01 12:05:00'));

    $reader = new DatabaseReviewStatusReader($store);

    expect(reviewReaderIds($reader->pendingWithin(['tenant_id' => 'store-1'])))->toBe(['rev_pending']);
});

it('still enumerates a lapsed but undecided request, reporting its expiry rather than a status', function (): void {
    $store = reviewReaderStore();
    $store->issue(reviewReaderRequest('rev_lapsed', expires: '2026-08-01 12:15:00'));

    $reader = new DatabaseReviewStatusReader($store);
    $views = $reader->pendingWithin(['tenant_id' => 'store-1']);

    // Expiry has no transition moment: nothing writes a lapsed status, so the reader must not
    // invent one. The consumer compares clocks against expiresAt, which is why it is on the view.
    expect(reviewReaderIds($views))->toBe(['rev_lapsed'])
        ->and($views[0]->status)->toBe(ReviewStatus::Pending)
        ->and($views[0]->expiresAt->format('Y-m-d H:i:s'))->toBe('2026-08-01 12:15:00');
});

// ── enumeration: order ───────────────────────────────────────────────────────────────────────────

it('orders by createdAt at second precision, then by request id', function (): void {
    $store = reviewReaderStore();

    // Inserted in an order that matches neither the contractual order nor its reverse.
    $store->issue(reviewReaderRequest('rev_c', created: '2026-08-01 12:00:01'));
    $store->issue(reviewReaderRequest('rev_a', created: '2026-08-01 12:00:02'));
    $store->issue(reviewReaderRequest('rev_b', created: '2026-08-01 12:00:01'));

    $reader = new DatabaseReviewStatusReader($store);

    // Chronology dominates, so rev_a is last despite sorting first by id; rev_b and rev_c share a
    // second and fall to the id tiebreaker.
    expect(reviewReaderIds($reader->pendingWithin(['tenant_id' => 'store-1'])))
        ->toBe(['rev_b', 'rev_c', 'rev_a']);
});

it('returns the same order as the in-memory reader for the same requests', function (): void {
    $requests = [
        reviewReaderRequest('rev_c', created: '2026-08-01 12:00:01'),
        reviewReaderRequest('rev_a', created: '2026-08-01 12:00:02'),
        reviewReaderRequest('rev_b', created: '2026-08-01 12:00:01'),
    ];

    $databaseStore = reviewReaderStore();
    $memoryStore = new InMemoryReviewRequestStore;

    foreach ($requests as $request) {
        $databaseStore->issue($request);
        $memoryStore->issue($request);
    }

    // The contract is one order, not two implementations that happen to agree today. Asserted as
    // equality between the readers so a change to either side shows up as the mismatch it is.
    expect(reviewReaderIds((new DatabaseReviewStatusReader($databaseStore))->pendingWithin(['tenant_id' => 'store-1'])))
        ->toBe(reviewReaderIds((new InMemoryReviewStatusReader($memoryStore))->pendingWithin(['tenant_id' => 'store-1'])));
});

// ── enumeration: refusals and honest emptiness ───────────────────────────────────────────────────

it('refuses an unscoped enumeration', function (): void {
    $reader = new DatabaseReviewStatusReader(reviewReaderStore());

    $reader->pendingWithin([]);
})->throws(InvalidArgumentException::class);

it('returns nothing on an install whose review table never got the approval_context column', function (): void {
    createReviewReaderTable(withApprovalContext: false);

    $store = reviewReaderStore();
    $store->issue(reviewReaderRequest('rev_a'));

    $reader = new DatabaseReviewStatusReader($store);

    // Honest emptiness, not a crash: no request on such an install carries a context, so none can
    // match a scope. verdict:validate is where the operator hears about the missing column.
    expect($reader->pendingWithin(['tenant_id' => 'store-1']))->toBe([]);
});

// ── the view the enumeration hands back ──────────────────────────────────────────────────────────

it('hydrates each match through the store, not from the candidate query', function (): void {
    $store = reviewReaderStore();
    $store->issue(reviewReaderRequest('rev_a', ['tenant_id' => 'store-1'], capability: 'orders.refund'));

    $reader = new DatabaseReviewStatusReader($store);
    $view = $reader->pendingWithin(['tenant_id' => 'store-1'])[0];

    // The candidate query reads two columns; everything else on the view can only have come from
    // the store's own row mapper, which is the point of pairing the reader to the store.
    expect($view->capability)->toBe('orders.refund')
        ->and($view->reason)->toBe('A human must review this cancellation.')
        ->and($view->createdAt->format('Y-m-d H:i:s'))->toBe('2026-08-01 12:00:00')
        ->and($view->approvalContext)->toBe(['tenant_id' => 'store-1'])
        ->and($view->resolvedBy)->toBeNull();
});

// ── container wiring: consumers bind nothing ─────────────────────────────────────────────────────

/**
 * Re-register the package with the review configuration in place.
 *
 * The ReviewRequestStore binding — and now the reader's, deliberately under the same condition — is
 * registered only when a review lane is configured at provider-register time, which in this suite is
 * before any test body runs. Forcing a re-register is what lets a test configure the lane and then
 * exercise the real factory and selector, rather than asserting against a store it hand-built.
 */
function registerReviewLane(): void
{
    app()->register(VerdictServiceProvider::class, force: true);
}

it('resolves the paired database reader for a configured database store, through the real factory', function (): void {
    config()->set('verdict.reviews.store', DatabaseReviewRequestStore::class);
    registerReviewLane();

    $reader = app(ReviewStatusReader::class);
    app(ReviewRequestStore::class)->issue(reviewReaderRequest('rev_a'));

    // The delivery #468 promises: a consumer of the shipped database store binds nothing and still
    // gets enumeration, instead of meeting the store-backed reader's refusal.
    expect($reader)->toBeInstanceOf(DatabaseReviewStatusReader::class)
        ->and(reviewReaderIds($reader->pendingWithin(['tenant_id' => 'store-1'])))->toBe(['rev_a']);
});

it('builds the configured store on its configured table, so the reader enumerates what the migration created', function (): void {
    // The store was resolved straight out of the container, which hands back the constructor's
    // defaults — so verdict.reviews.table was ignored while the migration stub reads that same key
    // and creates the table under it (#290). The store read one table, the lane wrote another, and a
    // reader over it reported an empty queue on any renamed install.
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(reviewReaderTable());
    config()->set('verdict.reviews.table', 'renamed_review_requests');
    config()->set('verdict.reviews.store', DatabaseReviewRequestStore::class);
    createReviewReaderTable();
    registerReviewLane();

    $store = app(ReviewRequestStore::class);
    assert($store instanceof DatabaseReviewRequestStore);
    $store->issue(reviewReaderRequest('rev_a'));

    expect($store->table())->toBe('renamed_review_requests')
        ->and(reviewReaderIds(app(ReviewStatusReader::class)->pendingWithin(['tenant_id' => 'store-1'])))
        ->toBe(['rev_a']);

    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists('renamed_review_requests');
});

it('resolves the paired in-memory reader when the in-memory store is configured', function (): void {
    config()->set('verdict.reviews.store', InMemoryReviewRequestStore::class);
    registerReviewLane();

    expect(app(ReviewStatusReader::class))->toBeInstanceOf(InMemoryReviewStatusReader::class);
});

it('uses a store that reads for itself as its own reader', function (): void {
    $store = new class implements ReviewRequestStore, ReviewStatusReader
    {
        public function issue(ReviewRequest $request): ReviewTransition
        {
            throw new RuntimeException('not used');
        }

        public function find(string $requestId): ?ReviewRequest
        {
            return null;
        }

        public function approve(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
        {
            throw new RuntimeException('not used');
        }

        public function reject(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
        {
            throw new RuntimeException('not used');
        }

        public function validate(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
        {
            throw new RuntimeException('not used');
        }

        public function consume(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
        {
            throw new RuntimeException('not used');
        }

        public function statusFor(string $requestId): ?ReviewStatusView
        {
            return null;
        }

        public function pendingWithin(array $scope): array
        {
            return [];
        }
    };

    app()->instance($store::class, $store);
    config()->set('verdict.reviews.store', $store::class);
    registerReviewLane();

    // Not wrapped: a store that already answers the read contract is the reader, and wrapping it
    // would shadow its own answers with the paired reader's.
    expect(app(ReviewStatusReader::class))->toBe($store);
});

it('leaves a custom store on the refusing store-backed reader', function (): void {
    $store = new class implements ReviewRequestStore
    {
        public function issue(ReviewRequest $request): ReviewTransition
        {
            throw new RuntimeException('not used');
        }

        public function find(string $requestId): ?ReviewRequest
        {
            return null;
        }

        public function approve(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
        {
            throw new RuntimeException('not used');
        }

        public function reject(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
        {
            throw new RuntimeException('not used');
        }

        public function validate(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
        {
            throw new RuntimeException('not used');
        }

        public function consume(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
        {
            throw new RuntimeException('not used');
        }
    };

    app()->instance($store::class, $store);
    config()->set('verdict.reviews.store', $store::class);
    registerReviewLane();

    // Unchanged behaviour for a store Verdict knows nothing about: the refusal still names the store
    // and tells its owner what to implement. Pairing the shipped stores does not quietly promise
    // enumeration for one that cannot answer it.
    $reader = app(ReviewStatusReader::class);

    expect($reader)->toBeInstanceOf(StoreBackedReviewStatusReader::class);

    $reader->pendingWithin(['tenant_id' => 'store-1']);
})->throws(LogicException::class, 'has no paired status reader');

it('binds no reader at all when no review lane is configured', function (): void {
    // The regression this guards: binding the reader more widely than the store it reads turns an
    // optional `?ReviewStatusReader $reader = null` dependency — a consumer asking "is the review
    // lane on?" — from null into a resolution failure on the unbound store.
    expect(config('verdict.reviews.store'))->toBeNull()
        ->and(app()->bound(ReviewStatusReader::class))->toBeFalse();

    $consumer = new class(null)
    {
        public function __construct(public ?ReviewStatusReader $reader) {}
    };

    expect(app()->make($consumer::class, ['reader' => null])->reader)->toBeNull();
});

// ── order does not depend on the database's collation ────────────────────────────────────────────

it('orders ids by their bytes even when the column collates case-insensitively', function (): void {
    // MySQL's default collation is case-insensitive, so `ORDER BY id` there puts 'ax…' before 'Zx…'
    // while a byte comparison — what the in-memory reader does, and what the contract means — puts
    // 'Zx…' first. Both are ordinary Str::random(64) output.
    //
    // SQLite defaults to BINARY, where the two agree, so its column is declared NOCASE to reproduce
    // the hazard locally — otherwise this guard could only fail on the matrix and would pass here
    // however the reader sorted. The other engines need no help: MySQL's default collation is
    // case-insensitive already, and PostgreSQL's follows the database locale, which for the usual
    // en_US.UTF-8 orders 'a' before 'Z' too. NOCASE is SQLite's spelling and exists nowhere else.
    $name = reviewReaderTable();
    $connection = app(DatabaseManager::class)->connection();
    $collation = $connection->getDriverName() === 'sqlite' ? 'nocase' : null;
    $schema = $connection->getSchemaBuilder();
    $schema->dropIfExists($name);
    $schema->create($name, function (Blueprint $table) use ($name, $collation): void {
        $id = $table->string('id', 64);

        if ($collation !== null) {
            $id->collation($collation);
        }

        $id->primary();
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
        $table->unique(['capability', 'binding_fingerprint'], $name.'_binding_unique');
    });

    $upper = 'Z'.str_repeat('x', 63);
    $lower = 'a'.str_repeat('x', 63);

    $databaseStore = reviewReaderStore();
    $memoryStore = new InMemoryReviewRequestStore;

    foreach ([$lower, $upper] as $id) {
        $request = reviewReaderRequest($id, created: '2026-08-01 12:00:00');
        $databaseStore->issue($request);
        $memoryStore->issue($request);
    }

    $fromDatabase = reviewReaderIds((new DatabaseReviewStatusReader($databaseStore))->pendingWithin(['tenant_id' => 'store-1']));

    expect($fromDatabase)->toBe([$upper, $lower])
        ->and($fromDatabase)
        ->toBe(reviewReaderIds((new InMemoryReviewStatusReader($memoryStore))->pendingWithin(['tenant_id' => 'store-1'])));
});
