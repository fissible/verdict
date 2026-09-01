<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\DatabaseApprovalStatusReader;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalStatusReader;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\PrunableApprovalReceiptStore;
use Fissible\Verdict\Tests\Support\CustomStatusReaderTestStore;
use Fissible\Verdict\Tests\Support\FrozenClock;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\ServiceProvider;

/**
 * Issue #357. `pendingWithin()` pulled every pending receipt with a non-empty context, filtered the
 * scope in PHP, then issued one `find()` per surviving candidate — an N+1 over an unbounded scan,
 * on the operator-facing queue, growing forever because nothing removes lapsed receipts.
 *
 * Two of the issue's prescribed fixes are refused here and the refusals are tested, because they
 * would trade the cliff for a wrong answer:
 *
 * - **No `expires_at > now` filter.** ADR 0031 §3 states "a lapsed-but-undecided receipt is still
 *   returned, with its expiresAt", and §5 makes that the reason the contract exists: `Pending` with
 *   a past `expiresAt` is "lapsed, undecided" as against "already decided", and until this contract
 *   shipped no consumer could tell them apart. Filtering lapsed rows out deletes the distinction.
 * - **No silent `LIMIT`.** A bounded page that does not say it is bounded tells an operator they
 *   have seen every pending approval in scope when they have not.
 *
 * What removes the accumulation is retention — pruning — which is an application decision about
 * old rows, not a reader deriving expiry.
 */
function scaleSchema(Builder $schema): void
{
    $schema->dropIfExists(verdictTable('approvals'));
    $schema->create(verdictTable('approvals'), function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('tool_call_id');
        $table->string('capability');
        $table->char('binding_fingerprint', 64);
        $table->string('status', 24);
        $table->text('reason')->nullable();
        $table->timestamp('expires_at');
        $table->string('approved_by')->nullable();
        $table->timestamp('approved_at')->nullable();
        $table->string('rejected_by')->nullable();
        $table->timestamp('rejected_at')->nullable();
        $table->timestamp('consumed_at')->nullable();
        $table->text('provenance')->nullable();
        $table->text('approval_context')->nullable();
        $table->timestamps();
        $table->unique(['tool_call_id', 'capability', 'binding_fingerprint'], 'verdict_approval_receipts_binding_unique');
    });
}

beforeEach(function (): void {
    scaleSchema(app(DatabaseManager::class)->connection()->getSchemaBuilder());
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('approvals'));
});

function scaleReceipt(
    string $id,
    ?array $approvalContext = ['tenant_id' => 7],
    ?DateTimeImmutable $createdAt = null,
    ?DateTimeImmutable $expiresAt = null,
    ApprovalReceiptStatus $status = ApprovalReceiptStatus::Pending,
): ApprovalReceipt {
    $now = $createdAt ?? new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    return new ApprovalReceipt(
        id: $id,
        toolCallId: 'call-'.$id,
        capability: 'orders.cancel',
        bindingFingerprint: hash('sha256', $id),
        provenance: null,
        approvalContext: $approvalContext,
        status: $status,
        reason: 'Confirm cancellation.',
        expiresAt: $expiresAt ?? $now->modify('+15 minutes'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $now,
        updatedAt: $now,
    );
}

function scaleDatabaseStore(): DatabaseApprovalReceiptStore
{
    return new DatabaseApprovalReceiptStore(app(DatabaseManager::class)->connection());
}

/** @return array{DatabaseApprovalReceiptStore|InMemoryApprovalReceiptStore, ApprovalStatusReader} */
function scaleDatabasePair(): array
{
    $store = scaleDatabaseStore();

    return [$store, new DatabaseApprovalStatusReader($store)];
}

/** @return array{DatabaseApprovalReceiptStore|InMemoryApprovalReceiptStore, ApprovalStatusReader} */
function scaleInMemoryPair(): array
{
    $store = new InMemoryApprovalReceiptStore;

    return [$store, new InMemoryApprovalStatusReader($store)];
}

dataset('scale pairs', [
    'database' => [fn (): array => scaleDatabasePair()],
    'in-memory' => [fn (): array => scaleInMemoryPair()],
]);

/** @return list<string> */
function enumeratedIds(ApprovalStatusReader $reader, array $scope = ['tenant_id' => 7]): array
{
    return array_map(
        static fn ($view): string => $view->receiptId,
        $reader->pendingWithin($scope),
    );
}

// ---------------------------------------------------------------------------------------------
// The contract the performance work must not break.
// ---------------------------------------------------------------------------------------------

it('still enumerates a lapsed but undecided receipt, with its expiry', function (Closure $pair): void {
    [$store, $reader] = $pair();
    $store->issue(scaleReceipt('receipt-live'));
    $store->issue(scaleReceipt('receipt-lapsed', expiresAt: new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'))));

    // ADR 0031 §5: Pending-with-a-past-expiresAt versus already-decided is the distinction this
    // contract exists to provide. An `expires_at > now` filter — which #357 prescribes — would
    // remove it, and the queue could no longer show an operator what lapsed unanswered.
    $views = $reader->pendingWithin(['tenant_id' => 7]);

    expect(array_map(static fn ($v): string => $v->receiptId, $views))
        ->toEqualCanonicalizing(['receipt-live', 'receipt-lapsed']);

    $lapsed = array_values(array_filter($views, static fn ($v): bool => $v->receiptId === 'receipt-lapsed'))[0];

    expect($lapsed->status)->toBe(ApprovalReceiptStatus::Pending)
        ->and($lapsed->expiresAt->format(DATE_ATOM))->toBe('2020-01-01T00:00:00+00:00');
})->with('scale pairs');

it('returns every match rather than a silent page', function (Closure $pair): void {
    [$store, $reader] = $pair();

    for ($i = 1; $i <= 60; $i++) {
        $store->issue(scaleReceipt(sprintf('receipt-%03d', $i)));
    }

    // A LIMIT that does not announce itself would tell an operator the queue is empty below the
    // cut. If enumeration is ever bounded it has to be an explicit, visible bound.
    expect($reader->pendingWithin(['tenant_id' => 7]))->toHaveCount(60);
})->with('scale pairs');

it('keeps the documented order after hydration', function (Closure $pair): void {
    [$store, $reader] = $pair();
    $earlier = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
    $later = new DateTimeImmutable('2026-08-01 12:05:00', new DateTimeZone('UTC'));

    // Insertion order, id order and creation order all disagree, so a hydration that returns rows
    // in whatever order the database hands back cannot pass: only created_at-then-id gives b,z,a.
    $store->issue(scaleReceipt('receipt-a', createdAt: $later));
    $store->issue(scaleReceipt('receipt-z', createdAt: $earlier));
    $store->issue(scaleReceipt('receipt-b', createdAt: $earlier));

    expect(enumeratedIds($reader))->toBe(['receipt-b', 'receipt-z', 'receipt-a']);
})->with('scale pairs');

it('enumerates only pending receipts inside the scope', function (Closure $pair): void {
    [$store, $reader] = $pair();
    $store->issue(scaleReceipt('receipt-match'));
    $store->issue(scaleReceipt('receipt-other-tenant', ['tenant_id' => 9]));
    $store->issue(scaleReceipt('receipt-no-context', null));
    $store->issue(scaleReceipt('receipt-empty-context', []));

    $decided = scaleReceipt('receipt-decided');
    $store->issue($decided);
    $store->approve($decided->id, $decided->toolCallId, 'user:42', new DateTimeImmutable('2026-08-01 12:01:00', new DateTimeZone('UTC')));

    expect(enumeratedIds($reader))->toBe(['receipt-match']);
})->with('scale pairs');

// ---------------------------------------------------------------------------------------------
// The N+1.
// ---------------------------------------------------------------------------------------------

it('issues the same query count for ten times the matches', function (): void {
    [$store, $reader] = scaleDatabasePair();

    for ($i = 1; $i <= 4; $i++) {
        $store->issue(scaleReceipt(sprintf('small-%03d', $i)));
    }

    // Warmed first: the column-presence check is memoized per store instance and would otherwise
    // be counted against the smaller run only.
    $reader->pendingWithin(['tenant_id' => 7]);

    $queries = 0;
    app(DatabaseManager::class)->connection()->listen(function () use (&$queries): void {
        $queries++;
    });

    $queries = 0;
    expect($reader->pendingWithin(['tenant_id' => 7]))->toHaveCount(4);
    $small = $queries;

    for ($i = 1; $i <= 40; $i++) {
        $store->issue(scaleReceipt(sprintf('large-%03d', $i)));
    }

    $queries = 0;
    expect($reader->pendingWithin(['tenant_id' => 7]))->toHaveCount(44);
    $large = $queries;

    // One candidate query plus one hydration, whatever the match count. Per-row find() makes this
    // 45 against 5, so equality across a tenfold difference is the assertion; the absolute value
    // pins that no third query crept in.
    expect($small)->toBe(2)
        ->and($large)->toBe($small);
});

it('hydrates in bounded batches rather than one statement of unbounded width', function (): void {
    [$store, $reader] = scaleDatabasePair();

    for ($i = 1; $i <= 1200; $i++) {
        $store->issue(scaleReceipt(sprintf('bulk-%04d', $i)));
    }

    $reader->pendingWithin(['tenant_id' => 7]);

    $widths = [];
    app(DatabaseManager::class)->connection()->listen(function ($query) use (&$widths): void {
        $widths[] = count($query->bindings);
    });

    $views = $reader->pendingWithin(['tenant_id' => 7]);

    // Counting rows alone would not catch this: SQLite here binds far more parameters than any
    // production driver allows, so an unchunked whereIn over 1200 ids succeeds locally and fails
    // on a real deployment. The width of each statement is the thing to assert.
    expect($views)->toHaveCount(1200)
        ->and($views[0]->receiptId)->toBe('bulk-0001')
        ->and($views[1199]->receiptId)->toBe('bulk-1200')
        ->and(max($widths))->toBeLessThanOrEqual(1000)
        ->and(count(array_filter($widths, static fn (int $w): bool => $w > 1)))->toBeGreaterThan(1);
});

it('drops a candidate that stopped being pending before it was hydrated', function (): void {
    [$store, $reader] = scaleDatabasePair();
    $store->issue(scaleReceipt('receipt-stays'));
    $store->issue(scaleReceipt('receipt-resolved-mid-read'));

    $reader->pendingWithin(['tenant_id' => 7]);

    $connection = app(DatabaseManager::class)->connection();
    $connection->listen(function ($query) use ($connection): void {
        // Fires after the candidate select and before hydration, which is the exact window
        // poll-consistency leaves open. Bulk hydration must keep the post-hydration recheck the
        // per-row version had, or a receipt decided mid-read is rendered actionable.
        if (str_contains($query->sql, 'approval_context') && str_contains($query->sql, 'select')) {
            $connection->table(verdictTable('approvals'))
                ->where('id', 'receipt-resolved-mid-read')
                ->update(['status' => ApprovalReceiptStatus::Approved->value]);
        }
    });

    expect(enumeratedIds($reader))->toBe(['receipt-stays']);
});

// ---------------------------------------------------------------------------------------------
// Retention: what actually removes the accumulation, and the one status it must not touch.
// ---------------------------------------------------------------------------------------------

it('prunes receipts whose deadline passed before the boundary, and reports how many', function (Closure $pair): void {
    [$store] = $pair();
    expect($store)->toBeInstanceOf(PrunableApprovalReceiptStore::class);

    $store->issue(scaleReceipt('receipt-ancient', expiresAt: new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'))));
    $store->issue(scaleReceipt('receipt-live'));

    expect($store->pruneExpired(new DateTimeImmutable('2020-06-01 00:00:00', new DateTimeZone('UTC'))))->toBe(1)
        ->and($store->find('receipt-ancient'))->toBeNull()
        ->and($store->find('receipt-live'))->not->toBeNull();
})->with('scale pairs');

it('treats the boundary as inclusive of a receipt that expired exactly on it', function (Closure $pair): void {
    [$store] = $pair();
    $boundary = new DateTimeImmutable('2026-07-01 12:00:00', new DateTimeZone('UTC'));
    $store->issue(scaleReceipt('receipt-on-boundary', expiresAt: $boundary));

    expect($store->pruneExpired($boundary))->toBe(1)
        ->and($store->find('receipt-on-boundary'))->toBeNull();
})->with('scale pairs');

it('never prunes a consumed receipt, however old', function (Closure $pair): void {
    [$store] = $pair();
    $expires = new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'));
    $before = new DateTimeImmutable('2019-12-31 23:00:00', new DateTimeZone('UTC'));

    $consumed = scaleReceipt('receipt-consumed', expiresAt: $expires);
    $store->issue($consumed);
    $store->approve($consumed->id, $consumed->toolCallId, 'user:42', $before);
    expect($store->consume($consumed->toolCallId, $consumed->bindingFingerprint, $before)->outcome)
        ->toBe(ApprovalOutcome::Consumed);

    // Consumed is the one status where deleting the row could admit a second execution: the row IS
    // the single-use gate for a capability with no atMostOnce() claim policy, and removing it frees
    // the (tool_call_id, capability, binding_fingerprint) key for a fresh receipt. Everything else
    // that expires never admitted anything, so its row is protecting nothing.
    expect($store->pruneExpired(new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'))))->toBe(0)
        ->and($store->find('receipt-consumed'))->not->toBeNull();
})->with('scale pairs');

it('still refuses a replay of a consumed binding after a prune sweep', function (Closure $pair): void {
    [$store] = $pair();
    $expires = new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'));
    $before = new DateTimeImmutable('2019-12-31 23:00:00', new DateTimeZone('UTC'));

    $consumed = scaleReceipt('receipt-used', expiresAt: $expires);
    $store->issue($consumed);
    $store->approve($consumed->id, $consumed->toolCallId, 'user:42', $before);
    $store->consume($consumed->toolCallId, $consumed->bindingFingerprint, $before);

    $store->pruneExpired(new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')));

    // The decisive case. If the consumed row had been pruned, re-proposing the identical binding
    // would mint a fresh Pending receipt, and one more human approval would execute the action a
    // second time. The row survives, so the re-proposal is refused without ever reaching a human.
    $replay = scaleReceipt('receipt-replay', expiresAt: new DateTimeImmutable('2026-08-01 12:15:00', new DateTimeZone('UTC')));
    $replay = new ApprovalReceipt(
        id: 'receipt-replay',
        toolCallId: $consumed->toolCallId,
        capability: $consumed->capability,
        bindingFingerprint: $consumed->bindingFingerprint,
        provenance: null,
        approvalContext: ['tenant_id' => 7],
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm cancellation.',
        expiresAt: new DateTimeImmutable('2026-08-01 12:15:00', new DateTimeZone('UTC')),
        approvedBy: null, approvedAt: null, rejectedBy: null, rejectedAt: null, consumedAt: null,
        createdAt: new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC')),
        updatedAt: new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC')),
    );

    expect($store->issue($replay)->outcome)->not->toBe(ApprovalOutcome::Issued)
        ->and($store->find('receipt-replay'))->toBeNull();
})->with('scale pairs');

it('prunes an expired receipt that never admitted an execution, at any other status', function (Closure $pair): void {
    [$store] = $pair();
    $expires = new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'));
    $decidedAt = new DateTimeImmutable('2019-12-31 23:00:00', new DateTimeZone('UTC'));

    $pending = scaleReceipt('receipt-pending', expiresAt: $expires);
    $approved = scaleReceipt('receipt-approved', expiresAt: $expires);
    $rejected = scaleReceipt('receipt-rejected', expiresAt: $expires);
    foreach ([$pending, $approved, $rejected] as $receipt) {
        $store->issue($receipt);
    }
    $store->approve($approved->id, $approved->toolCallId, 'user:42', $decidedAt);
    $store->reject($rejected->id, $rejected->toolCallId, 'user:42', $decidedAt);

    // Approved-but-never-consumed is included deliberately: it lapsed without admitting anything,
    // so its row protects no execution.
    expect($store->pruneExpired(new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'))))->toBe(3)
        ->and($store->find('receipt-pending'))->toBeNull()
        ->and($store->find('receipt-approved'))->toBeNull()
        ->and($store->find('receipt-rejected'))->toBeNull();
})->with('scale pairs');

it('leaves every path refusing after a prune, issuance included', function (Closure $pair): void {
    [$store] = $pair();
    $receipt = scaleReceipt('receipt-gone', expiresAt: new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC')));
    $store->issue($receipt);
    $store->pruneExpired(new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')));

    $now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    // Before the prune each of these refused with Expired; after it they refuse with NotFound.
    // Deleting security state may change which refusal is reported, never that it refuses.
    expect($store->approve($receipt->id, $receipt->toolCallId, 'user:42', $now)->outcome)
        ->toBe(ApprovalOutcome::NotFound)
        ->and($store->reject($receipt->id, $receipt->toolCallId, 'user:42', $now)->outcome)
        ->toBe(ApprovalOutcome::NotFound)
        ->and($store->consume($receipt->toolCallId, $receipt->bindingFingerprint, $now)->outcome)
        ->toBe(ApprovalOutcome::NotFound)
        ->and($store->validate($receipt->toolCallId, $receipt->bindingFingerprint, $now)->outcome)
        ->toBe(ApprovalOutcome::NotFound);

    // Issuance is the one path that opens rather than refuses: a binding whose lapsed, unconsumed
    // receipt was pruned can be proposed again, and the fresh receipt is Pending — it admits
    // nothing until a human decides it.
    $reissued = scaleReceipt('receipt-fresh', expiresAt: new DateTimeImmutable('2026-08-01 12:15:00', new DateTimeZone('UTC')));

    expect($store->issue($reissued)->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($store->find('receipt-fresh')?->status)->toBe(ApprovalReceiptStatus::Pending);
})->with('scale pairs');

it('prunes nothing at a boundary that would erase the lapsed queue', function (Closure $pair): void {
    [$store, $reader] = $pair();
    $store->issue(scaleReceipt('receipt-just-lapsed', expiresAt: new DateTimeImmutable('2026-08-01 11:59:00', new DateTimeZone('UTC'))));

    // Retention is deliberately not "now". Pruning at now would delete a receipt the moment it
    // lapsed — erasing exactly the lapsed-undecided rows ADR 0031 §5 says the queue must show, and
    // doing by deletion what the refused expiry filter would have done by omission.
    expect($store->pruneExpired(new DateTimeImmutable('2026-07-02 12:00:00', new DateTimeZone('UTC'))))->toBe(0)
        ->and(enumeratedIds($reader))->toBe(['receipt-just-lapsed']);
})->with('scale pairs');

it('reads a pruned receipt back as absent, so retention changes history', function (Closure $pair): void {
    [$store, $reader] = $pair();
    $store->issue(scaleReceipt('receipt-forgotten', expiresAt: new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'))));
    $store->pruneExpired(new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')));

    // A consumer holding a receipt id across a prune sweep sees null, not a decided receipt. That
    // is the cost of retention and belongs in the documentation, not in a surprise.
    expect($reader->statusFor('receipt-forgotten'))->toBeNull()
        ->and($store->find('receipt-forgotten'))->toBeNull();
})->with('scale pairs');

// ---------------------------------------------------------------------------------------------
// The command. Retention is the operator's decision, so there is no default.
// ---------------------------------------------------------------------------------------------

it('refuses to prune when no retention has been chosen', function (): void {
    app()->instance(Clock::class, new FrozenClock);
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.approvals.retention_days', null);
    app()->forgetInstance(ApprovalReceiptStore::class);

    $store = app(ApprovalReceiptStore::class);
    $store->issue(scaleReceipt('receipt-ancient', expiresAt: new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'))));

    // Deleting security state on a schedule the operator never chose is worse than not pruning, so
    // an unconfigured retention is a refusal rather than a default.
    $this->artisan('verdict:prune-approvals')->assertExitCode(1);

    expect($store->find('receipt-ancient'))->not->toBeNull();
});

it('prunes against the configured retention window, and against an explicit override', function (): void {
    // The command reads the Clock, and Feature tests otherwise run on the system clock — against
    // which every fixture timestamp here is already ancient, so a 30-day window would sweep the
    // receipt this test needs to survive and the assertion would pass or fail by the calendar.
    app()->instance(Clock::class, new FrozenClock);
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.approvals.retention_days', 30);
    app()->forgetInstance(ApprovalReceiptStore::class);

    $store = app(ApprovalReceiptStore::class);
    $store->issue(scaleReceipt('receipt-ancient', expiresAt: new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'))));
    $store->issue(scaleReceipt('receipt-recent', expiresAt: new DateTimeImmutable('2026-07-31 12:00:00', new DateTimeZone('UTC'))));

    $this->artisan('verdict:prune-approvals')->assertExitCode(0);

    // The recent one lapsed inside the window, so the queue keeps it.
    expect($store->find('receipt-ancient'))->toBeNull()
        ->and($store->find('receipt-recent'))->not->toBeNull();

    $this->artisan('verdict:prune-approvals', ['--days' => 0])->assertExitCode(0);

    expect($store->find('receipt-recent'))->toBeNull();
});

it('says so and succeeds when the configured store cannot be pruned', function (): void {
    config()->set('verdict.approvals.store', CustomStatusReaderTestStore::class);
    config()->set('verdict.approvals.retention_days', 30);
    app()->forgetInstance(ApprovalReceiptStore::class);

    // Mirrors verdict:prune-rate-limits: a store with no retention story is not an error.
    $this->artisan('verdict:prune-approvals')
        ->expectsOutputToContain('does not require pruning')
        ->assertExitCode(0);
});

it('publishes the enumeration index migration with the other approval migrations', function (): void {
    $published = collect(ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-approval-migrations',
    ));

    // An unpublished stub is a migration no adopter ever runs, so the index would exist only in
    // this repository's tests.
    expect($published->keys()->contains(fn (string $path): bool => str_contains($path, 'add_pending_enumeration_index_to_verdict_approval_receipts_table')))
        ->toBeTrue();
});
