<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ReviewStatusReader;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\InMemoryReviewStatusReader;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Reviews\ReviewStatusView;
use Fissible\Verdict\Reviews\StoreBackedReviewStatusReader;
use Fissible\Verdict\Support\ApproverSummary;

// ADR 0035 §4 — the review lane's read surface, which realizes ADR 0031's discipline through a SEPARATE
// typed reader (not a widening of ApprovalStatusReader). Because a review is id-addressed, the reader is
// simpler than the confirmation one: statusFor(requestId) is unambiguous and there is no tool-call
// collision surface. Reads are observational (never mutate), poll-consistent, and expiry is the consumer's
// clock comparison — the view reports the persisted status plus expiresAt and never a computed Expired.
// Enumeration is scoped-or-refused: pendingWithin(non-empty scope) matches by approvalContext containment;
// a store with no paired enumerating reader refuses rather than pretend.

function readerPending(
    string $id,
    ?array $context = ['tenant_id' => 'store-1'],
    string $created = '2026-08-30 12:00:00',
    string $expires = '2026-08-30 13:00:00',
    ?ApproverSummary $summary = null,
): ReviewRequest {
    return ReviewRequest::pending(
        id: $id,
        capability: 'orders.cancel',
        bindingFingerprint: 'bind-'.$id,
        approvalContext: $context,
        createdAt: new DateTimeImmutable($created),
        expiresAt: new DateTimeImmutable($expires),
        reason: 'A human must review this cancellation.',
        approverSummary: $summary,
    );
}

/** @return list<string> the ids of the returned views, in order */
function viewIds(array $views): array
{
    return array_map(static fn (ReviewStatusView $v): string => $v->requestId, $views);
}

dataset('reviewReaders', [
    'in-memory' => [static fn (InMemoryReviewRequestStore $s): ReviewStatusReader => new InMemoryReviewStatusReader($s)],
    'store-backed' => [static fn (InMemoryReviewRequestStore $s): ReviewStatusReader => new StoreBackedReviewStatusReader($s)],
]);

// ── ReviewStatusView mapping ─────────────────────────────────────────────────────────────────────

it('projects every field of a request onto its status view, exposing only the summary fingerprint', function (): void {
    $request = new ReviewRequest(
        id: 'rev_1',
        capability: 'orders.cancel',
        bindingFingerprint: 'bind-abc',
        approvalContext: ['tenant_id' => 'store-1', 'conversation_id' => 'c-42'],
        provenance: null,
        approverSummary: new ApproverSummary(content: 'Cancel order 7001.', fingerprint: 'summary-fp-1'),
        status: ReviewStatus::Approved,
        reason: 'A human must review this cancellation.',
        createdAt: new DateTimeImmutable('2026-08-30 12:00:00'),
        expiresAt: new DateTimeImmutable('2026-08-30 13:00:00'),
        resolvedBy: 'reviewer-7',
        resolvedAt: new DateTimeImmutable('2026-08-30 12:30:00'),
        consumedAt: null,
    );

    $view = ReviewStatusView::fromRequest($request);

    expect($view->requestId)->toBe('rev_1')
        ->and($view->capability)->toBe('orders.cancel')
        ->and($view->status)->toBe(ReviewStatus::Approved)
        ->and($view->reason)->toBe('A human must review this cancellation.')
        ->and($view->summaryFingerprint)->toBe('summary-fp-1') // the fingerprint, not the content
        ->and($view->createdAt)->toEqual(new DateTimeImmutable('2026-08-30 12:00:00'))
        ->and($view->expiresAt)->toEqual(new DateTimeImmutable('2026-08-30 13:00:00'))
        ->and($view->resolvedBy)->toBe('reviewer-7')
        ->and($view->resolvedAt)->toEqual(new DateTimeImmutable('2026-08-30 12:30:00'))
        ->and($view->approvalContext)->toBe(['tenant_id' => 'store-1', 'conversation_id' => 'c-42']);
});

it('exposes exactly the ADR §4 field set and nothing more — the DTO shape is the privacy contract', function (): void {
    // A view that also surfaced the summary CONTENT, bindingFingerprint, provenance, or consumedAt would
    // leak beyond what an observer is entitled to. The public property set IS the observable contract.
    $names = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(ReviewStatusView::class))->getProperties(ReflectionProperty::IS_PUBLIC),
    );
    sort($names);

    expect($names)->toBe([
        'approvalContext',
        'capability',
        'createdAt',
        'expiresAt',
        'reason',
        'requestId',
        'resolvedAt',
        'resolvedBy',
        'status',
        'summaryFingerprint',
    ]);
});

it('reports a null summary fingerprint when the request carries no approver summary', function (): void {
    $view = ReviewStatusView::fromRequest(readerPending('rev_1'));

    expect($view->summaryFingerprint)->toBeNull();
});

it('collapses a null request to a null view, and a present one to a view', function (): void {
    expect(ReviewStatusView::fromNullableRequest(null))->toBeNull()
        ->and(ReviewStatusView::fromNullableRequest(readerPending('rev_1')))->toBeInstanceOf(ReviewStatusView::class);
});

it('never reports a computed Expired status — a lapsed Pending request views as Pending plus its expiry', function (): void {
    // Mirrors ApprovalStatusView: expiry has no transition moment; the view reports the persisted status and
    // the expiresAt, and the consumer compares clocks. There is deliberately no Expired status on the view.
    $view = ReviewStatusView::fromRequest(readerPending('rev_1', expires: '2026-08-30 11:00:00'));

    expect($view->status)->toBe(ReviewStatus::Pending)
        ->and($view->expiresAt)->toEqual(new DateTimeImmutable('2026-08-30 11:00:00'));
});

// ── statusFor: both readers ride the store's own lookup ──────────────────────────────────────────

it('returns the status view for a known request id', function (callable $makeReader): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_1'));
    $reader = $makeReader($store);

    $view = $reader->statusFor('rev_1');

    expect($view?->requestId)->toBe('rev_1')
        ->and($view?->status)->toBe(ReviewStatus::Pending);
})->with('reviewReaders');

it('returns null for an unknown request id', function (callable $makeReader): void {
    $reader = $makeReader(new InMemoryReviewRequestStore);

    expect($reader->statusFor('nope'))->toBeNull();
})->with('reviewReaders');

it('reads a decided request back with its resolution, not only pending ones', function (callable $makeReader): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_1'));
    $store->approve('rev_1', 'reviewer-7', new DateTimeImmutable('2026-08-30 12:30:00'));
    $reader = $makeReader($store);

    $view = $reader->statusFor('rev_1');

    expect($view?->status)->toBe(ReviewStatus::Approved)
        ->and($view?->resolvedBy)->toBe('reviewer-7')
        ->and($view?->resolvedAt)->toEqual(new DateTimeImmutable('2026-08-30 12:30:00'));
})->with('reviewReaders');

it('reads a rejected request back through either reader', function (callable $makeReader): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_1'));
    $store->reject('rev_1', 'reviewer-9', new DateTimeImmutable('2026-08-30 12:30:00'));

    $view = $makeReader($store)->statusFor('rev_1');

    expect($view?->status)->toBe(ReviewStatus::Rejected)
        ->and($view?->resolvedBy)->toBe('reviewer-9');
})->with('reviewReaders');

it('reads a consumed request back through either reader', function (callable $makeReader): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_1'));
    $store->approve('rev_1', 'reviewer-7', new DateTimeImmutable('2026-08-30 12:30:00'));
    $store->consume('orders.cancel', 'bind-rev_1', new DateTimeImmutable('2026-08-30 12:45:00'));

    $view = $makeReader($store)->statusFor('rev_1');

    expect($view?->status)->toBe(ReviewStatus::Consumed)
        ->and($view?->resolvedBy)->toBe('reviewer-7');
})->with('reviewReaders');

it('maps the full DTO through the reader path, not only through fromRequest()', function (callable $makeReader): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending(
        'rev_full',
        context: ['tenant_id' => 'store-1', 'conversation_id' => 'c-42'],
        summary: new ApproverSummary(content: 'Cancel order 7001.', fingerprint: 'summary-fp-1'),
    ));
    // Decide it so resolvedBy/resolvedAt are non-null and the reader path must carry every field.
    $store->approve('rev_full', 'reviewer-7', new DateTimeImmutable('2026-08-30 12:30:00'));

    $view = $makeReader($store)->statusFor('rev_full');

    expect($view?->requestId)->toBe('rev_full')
        ->and($view?->capability)->toBe('orders.cancel')
        ->and($view?->status)->toBe(ReviewStatus::Approved)
        ->and($view?->reason)->toBe('A human must review this cancellation.')
        ->and($view?->summaryFingerprint)->toBe('summary-fp-1')
        ->and($view?->approvalContext)->toBe(['tenant_id' => 'store-1', 'conversation_id' => 'c-42'])
        ->and($view?->createdAt)->toEqual(new DateTimeImmutable('2026-08-30 12:00:00'))
        ->and($view?->expiresAt)->toEqual(new DateTimeImmutable('2026-08-30 13:00:00'))
        ->and($view?->resolvedBy)->toBe('reviewer-7')
        ->and($view?->resolvedAt)->toEqual(new DateTimeImmutable('2026-08-30 12:30:00'));
})->with('reviewReaders');

it('reads without mutating the stored request — statusFor and pendingWithin are observational', function (): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_1'));
    $before = $store->find('rev_1');
    $reader = new InMemoryReviewStatusReader($store);

    $reader->statusFor('rev_1');
    $reader->pendingWithin(['tenant_id' => 'store-1']);

    $after = $store->find('rev_1');

    expect($after?->status)->toBe(ReviewStatus::Pending)
        ->and($after?->resolvedBy)->toBeNull()
        ->and($after?->resolvedAt)->toBeNull()
        ->and($after?->consumedAt)->toBeNull()
        ->and($after)->toBe($before); // a read that transitioned would replace the stored instance
});

it('reflects writes made after the reader was created — reads are poll-consistent, not a construction snapshot', function (callable $makeReader): void {
    $store = new InMemoryReviewRequestStore;
    $reader = $makeReader($store); // built BEFORE any write

    expect($reader->statusFor('rev_1'))->toBeNull();

    $store->issue(readerPending('rev_1'));
    expect($reader->statusFor('rev_1')?->status)->toBe(ReviewStatus::Pending);

    $store->approve('rev_1', 'reviewer-7', new DateTimeImmutable('2026-08-30 12:30:00'));
    expect($reader->statusFor('rev_1')?->status)->toBe(ReviewStatus::Approved);
})->with('reviewReaders');

it('reflects post-construction writes in enumeration too', function (): void {
    $store = new InMemoryReviewRequestStore;
    $reader = new InMemoryReviewStatusReader($store); // built BEFORE any write

    expect($reader->pendingWithin(['tenant_id' => 'store-1']))->toBe([]);

    $store->issue(readerPending('rev_1'));
    expect(viewIds($reader->pendingWithin(['tenant_id' => 'store-1'])))->toBe(['rev_1']);

    // Once decided it leaves the pending enumeration, through the same reader instance.
    $store->approve('rev_1', 'reviewer-7', new DateTimeImmutable('2026-08-30 12:30:00'));
    expect($reader->pendingWithin(['tenant_id' => 'store-1']))->toBe([]);
});

// ── pendingWithin: enumeration over the paired in-memory reader ──────────────────────────────────

it('refuses an unscoped enumeration — an empty scope throws', function (): void {
    $reader = new InMemoryReviewStatusReader(new InMemoryReviewRequestStore);

    expect(fn (): mixed => $reader->pendingWithin([]))->toThrow(InvalidArgumentException::class);
});

it('returns only pending requests whose context contains the whole scope', function (): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_1', context: ['tenant_id' => 'store-1']));
    $store->issue(readerPending('rev_2', context: ['tenant_id' => 'store-2'])); // different tenant
    $store->issue(readerPending('rev_3', context: ['tenant_id' => 'store-1', 'team' => 'ops'])); // superset matches
    $reader = new InMemoryReviewStatusReader($store);

    expect(viewIds($reader->pendingWithin(['tenant_id' => 'store-1'])))->toBe(['rev_1', 'rev_3']);
});

it('requires every key of a multi-key scope — a request missing one key does not match', function (): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_missing', context: ['tenant_id' => 'store-1'])); // no conversation_id
    $store->issue(readerPending('rev_exact', context: ['tenant_id' => 'store-1', 'conversation_id' => 'c-42']));
    $store->issue(readerPending('rev_superset', context: ['tenant_id' => 'store-1', 'conversation_id' => 'c-42', 'team' => 'ops']));
    $reader = new InMemoryReviewStatusReader($store);

    expect(viewIds($reader->pendingWithin(['tenant_id' => 'store-1', 'conversation_id' => 'c-42'])))
        ->toBe(['rev_exact', 'rev_superset']);
});

it('never enumerates a decided request — only Pending', function (): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_pending'));
    $store->issue(readerPending('rev_approved'));
    $store->approve('rev_approved', 'reviewer-7', new DateTimeImmutable('2026-08-30 12:30:00'));
    $store->issue(readerPending('rev_rejected'));
    $store->reject('rev_rejected', 'reviewer-9', new DateTimeImmutable('2026-08-30 12:30:00'));
    $reader = new InMemoryReviewStatusReader($store);

    expect(viewIds($reader->pendingWithin(['tenant_id' => 'store-1'])))->toBe(['rev_pending']);
});

it('never enumerates a request with null or empty context', function (): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_null', context: null));
    $store->issue(readerPending('rev_empty', context: []));
    $store->issue(readerPending('rev_scoped', context: ['tenant_id' => 'store-1']));
    $reader = new InMemoryReviewStatusReader($store);

    expect(viewIds($reader->pendingWithin(['tenant_id' => 'store-1'])))->toBe(['rev_scoped']);
});

it('matches scope values by exact type — an integer scope does not match a string context', function (): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_string', context: ['tenant_id' => '1']));
    $store->issue(readerPending('rev_int', context: ['tenant_id' => 1]));
    $reader = new InMemoryReviewStatusReader($store);

    expect(viewIds($reader->pendingWithin(['tenant_id' => 1])))->toBe(['rev_int']);
});

it('still returns a lapsed-but-undecided request with its expiresAt — expiry is not an enumeration filter', function (): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_lapsed', expires: '2026-08-30 11:00:00')); // long past
    $reader = new InMemoryReviewStatusReader($store);

    $views = $reader->pendingWithin(['tenant_id' => 'store-1']);

    expect(viewIds($views))->toBe(['rev_lapsed'])
        ->and($views[0]->status)->toBe(ReviewStatus::Pending)
        ->and($views[0]->expiresAt)->toEqual(new DateTimeImmutable('2026-08-30 11:00:00'));
});

it('orders the enumeration by createdAt at SECOND precision then request id', function (): void {
    // rev_a's microsecond (.9) is AFTER rev_b's (.1), but both fall in the same second; at second precision
    // the tie breaks on id → [rev_a, rev_b]. A reader that sorted on microseconds would return [rev_b, rev_a],
    // so this discriminates second-precision (which the database reader inherits from its column) from finer.
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_b', created: '2026-08-30 12:00:05.100000'));
    $store->issue(readerPending('rev_a', created: '2026-08-30 12:00:05.900000'));
    $store->issue(readerPending('rev_early', created: '2026-08-30 12:00:00.500000'));
    $reader = new InMemoryReviewStatusReader($store);

    expect(viewIds($reader->pendingWithin(['tenant_id' => 'store-1'])))->toBe(['rev_early', 'rev_a', 'rev_b']);
});

it('returns status views, not requests', function (): void {
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending('rev_1'));
    $reader = new InMemoryReviewStatusReader($store);

    expect($reader->pendingWithin(['tenant_id' => 'store-1'])[0])->toBeInstanceOf(ReviewStatusView::class);
});

it('projects the full DTO in the enumeration path too, with null resolution fields', function (): void {
    // pendingWithin maps its own views — a separate path from statusFor — so it must carry every field.
    $store = new InMemoryReviewRequestStore;
    $store->issue(readerPending(
        'rev_full',
        context: ['tenant_id' => 'store-1', 'conversation_id' => 'c-42'],
        summary: new ApproverSummary(content: 'Cancel order 7001.', fingerprint: 'summary-fp-1'),
    ));
    $reader = new InMemoryReviewStatusReader($store);

    $view = $reader->pendingWithin(['tenant_id' => 'store-1'])[0];

    expect($view->requestId)->toBe('rev_full')
        ->and($view->capability)->toBe('orders.cancel')
        ->and($view->status)->toBe(ReviewStatus::Pending)
        ->and($view->reason)->toBe('A human must review this cancellation.')
        ->and($view->summaryFingerprint)->toBe('summary-fp-1')
        ->and($view->approvalContext)->toBe(['tenant_id' => 'store-1', 'conversation_id' => 'c-42'])
        ->and($view->createdAt)->toEqual(new DateTimeImmutable('2026-08-30 12:00:00'))
        ->and($view->expiresAt)->toEqual(new DateTimeImmutable('2026-08-30 13:00:00'))
        ->and($view->resolvedBy)->toBeNull()
        ->and($view->resolvedAt)->toBeNull();
});

// ── the store-backed reader cannot enumerate ─────────────────────────────────────────────────────

it('the store-backed reader refuses enumeration but still validates the scope first', function (): void {
    $reader = new StoreBackedReviewStatusReader(new InMemoryReviewRequestStore);

    // Empty scope is rejected as InvalidArgumentException even here (assertScope precedes the refusal)…
    expect(fn (): mixed => $reader->pendingWithin([]))->toThrow(InvalidArgumentException::class);
    // …and a well-formed scope refuses with a LogicException, because a bare store cannot enumerate.
    expect(fn (): mixed => $reader->pendingWithin(['tenant_id' => 'store-1']))->toThrow(LogicException::class);
});

// ── contract conformance ─────────────────────────────────────────────────────────────────────────

it('both readers implement the ReviewStatusReader contract', function (): void {
    $store = new InMemoryReviewRequestStore;

    expect(new InMemoryReviewStatusReader($store))->toBeInstanceOf(ReviewStatusReader::class)
        ->and(new StoreBackedReviewStatusReader($store))->toBeInstanceOf(ReviewStatusReader::class);
});
