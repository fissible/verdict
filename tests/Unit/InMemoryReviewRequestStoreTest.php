<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Reviews\ApproverSummary;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;

// ADR 0035 §6 — the review-request store's lifecycle, idempotency, and single-use admission. Adapts
// the confirmation lane's ApprovalReceiptStore for a review that is OUT OF BAND: there is no live tool
// call, so a decision (approve/reject) is addressed by the request's own id, while the execution-side
// checks (validate/consume) are addressed by (capability, bindingFingerprint). Issuance is idempotent
// per that binding; consume is single-use and atomic; expiry (a >= boundary, per the value object) is
// checked at decision AND at consumption.
//
// This store is NOT a security boundary: it does not enforce the ReviewDecisionAuthorizer (slice 3) —
// any caller holding an id can resolve a request, so a later manager/gate slice must prove authorization
// happens BEFORE these mutators are called. What the store must guarantee here is that (a) a REFUSED
// operation never mutates the record (proven with a full lifecycle-snapshot equality via
// `refusedWithoutMutation`), and (b) a SUCCESSFUL transition preserves all immutable review material.
// Assertions read fields, not object identity, so the same suite can back the database store in slice 5.

beforeEach(function (): void {
    $this->store = new InMemoryReviewRequestStore;
});

// ── issue: idempotent per (capability, bindingFingerprint) ───────────────────────────────────────

it('issues a new request and makes it findable by id', function (): void {
    $transition = $this->store->issue(reviewRequest('rev_1'));

    $stored = $this->store->find('rev_1');

    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($transition->request?->id)->toBe('rev_1')
        ->and($stored)->not->toBeNull()
        ->and($stored?->id)->toBe('rev_1')
        ->and($stored?->capability)->toBe('orders.cancel')
        ->and($stored?->bindingFingerprint)->toBe('bind-abc')
        ->and($stored?->status)->toBe(ReviewStatus::Pending);
});

it('stores a fully-populated request intact on issue — nothing is dropped on the initial write', function (): void {
    // Without this, a store could drop approvalContext/provenance/summary/timestamps at write time and
    // still pass every transition-preservation test (they only prove the stored record is carried forward).
    $transition = $this->store->issue(richReviewRequest());

    $stored = $this->store->find('rev_rich');
    expectMaterialPreserved($stored);
    expectMaterialPreserved($transition->request); // the returned transition is canonical too
    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($stored?->status)->toBe(ReviewStatus::Pending)
        ->and($stored?->resolvedBy)->toBeNull()
        ->and($stored?->resolvedAt)->toBeNull()
        ->and($stored?->consumedAt)->toBeNull();
});

it('does not overwrite the stored record when the same id and binding are re-issued with altered material', function (): void {
    $this->store->issue(richReviewRequest());

    // A second issuance under the SAME id and binding, carrying different material, must be an
    // idempotent no-op returning the existing record — never a silent overwrite of the durable one.
    $tampered = new ReviewRequest(
        id: 'rev_rich',
        capability: 'orders.cancel',
        bindingFingerprint: 'bind-rich',
        approvalContext: ['tenant_id' => 'attacker'],
        provenance: ProposalProvenance::unknown(),
        approverSummary: new ApproverSummary(content: 'tampered', fingerprint: 'tampered-fp'),
        status: ReviewStatus::Pending,
        reason: 'tampered',
        createdAt: at('12:05'),
        expiresAt: at('14:00'),
        resolvedBy: null,
        resolvedAt: null,
        consumedAt: null,
    );

    $transition = $this->store->issue($tampered);

    expect($transition->outcome)->toBe(ReviewOutcome::Existing);
    // Both the stored record AND the returned transition must be the ORIGINAL — a store that returns the
    // attacker's altered payload as "Existing" leaks tampered material to the caller.
    expectMaterialPreserved($this->store->find('rev_rich'));
    expectMaterialPreserved($transition->request);
});

it('returns the existing request when the same binding is re-issued while pending', function (): void {
    $this->store->issue(reviewRequest('rev_1'));

    $transition = $this->store->issue(reviewRequest('rev_2')); // same capability + binding, new id

    expect($transition->outcome)->toBe(ReviewOutcome::Existing)
        ->and($transition->request?->id)->toBe('rev_1')
        ->and($this->store->find('rev_2'))->toBeNull();
});

it('returns the canonical original — not a new-id payload — when a different id reuses the binding', function (): void {
    $this->store->issue(richReviewRequest()); // rev_rich, bind-rich, declared(3,2), summary-fp-1

    // A different id, the SAME capability+binding, carrying altered material: the store must return the
    // stored original as Existing, never echo back the new payload, and must not store the new id.
    $tampered = new ReviewRequest(
        id: 'rev_other',
        capability: 'orders.cancel',
        bindingFingerprint: 'bind-rich',
        approvalContext: ['tenant_id' => 'attacker'],
        provenance: ProposalProvenance::unknown(),
        approverSummary: new ApproverSummary(content: 'tampered', fingerprint: 'tampered-fp'),
        status: ReviewStatus::Pending,
        reason: 'tampered',
        createdAt: at('12:05'),
        expiresAt: at('14:00'),
        resolvedBy: null,
        resolvedAt: null,
        consumedAt: null,
    );

    $transition = $this->store->issue($tampered);

    expect($transition->outcome)->toBe(ReviewOutcome::Existing)
        ->and($transition->request?->id)->toBe('rev_rich')
        ->and($this->store->find('rev_other'))->toBeNull();
    expectMaterialPreserved($transition->request);
    expectMaterialPreserved($this->store->find('rev_rich'));
});

it('returns the existing, canonical request when the same binding is re-issued while approved', function (): void {
    $this->store->issue(richReviewRequest());
    $this->store->approve('rev_rich', 'reviewer-7', at('12:30'));

    $transition = $this->store->issue(reviewRequest('rev_2', binding: 'bind-rich'));

    expect($transition->outcome)->toBe(ReviewOutcome::Existing)
        ->and($transition->request?->id)->toBe('rev_rich')
        ->and($this->store->find('rev_2'))->toBeNull();
    expectMaterialPreserved($transition->request);
    expectMaterialPreserved($this->store->find('rev_rich'));
});

it('refuses to reissue a binding whose request was rejected — it stays refused and untouched', function (): void {
    seedRejected($this->store);

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->issue(reviewRequest('rev_2')), ReviewOutcome::InvalidState);
    expect($this->store->find('rev_2'))->toBeNull();
});

it('refuses to reissue a binding whose request was already consumed, leaving it untouched', function (): void {
    seedConsumed($this->store);

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->issue(reviewRequest('rev_2')), ReviewOutcome::InvalidState);
    expect($this->store->find('rev_2'))->toBeNull();
});

it('returns the existing request when reissued BEFORE its expiry', function (): void {
    $this->store->issue(reviewRequest('rev_1', expiresAt: at('13:00')));

    // A second proposal minted before the deadline: the existing request is still live.
    $transition = $this->store->issue(reviewRequest('rev_2', createdAt: at('12:30'), expiresAt: at('13:30')));

    expect($transition->outcome)->toBe(ReviewOutcome::Existing)
        ->and($transition->request?->id)->toBe('rev_1');
});

it('reports an expired existing request rather than reissuing it, leaving it untouched', function (): void {
    $this->store->issue(reviewRequest('rev_1', expiresAt: at('13:00')));

    // The proposed request's trusted issuance timestamp (createdAt) is the "now" for the expiry check.
    refusedWithoutMutation(
        $this->store,
        fn (): mixed => $this->store->issue(reviewRequest('rev_2', createdAt: at('13:30'), expiresAt: at('14:00'))),
        ReviewOutcome::Expired,
    );
    expect($this->store->find('rev_2'))->toBeNull();
});

it('treats the expiry instant itself as expired when reissuing (>= boundary)', function (): void {
    $this->store->issue(reviewRequest('rev_1', expiresAt: at('13:00')));

    refusedWithoutMutation(
        $this->store,
        fn (): mixed => $this->store->issue(reviewRequest('rev_2', createdAt: at('13:00'), expiresAt: at('14:00'))),
        ReviewOutcome::Expired,
    );
});

it('treats a changed proposal (different binding) as a new, independent request', function (): void {
    $this->store->issue(reviewRequest('rev_1', binding: 'bind-abc'));

    $transition = $this->store->issue(reviewRequest('rev_2', binding: 'bind-xyz'));

    // Approving the OLD binding must not admit the NEW one.
    $this->store->approve('rev_1', 'reviewer-7', at('12:30'));

    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($this->store->find('rev_1')?->bindingFingerprint)->toBe('bind-abc')
        ->and($this->store->find('rev_2')?->status)->toBe(ReviewStatus::Pending)
        ->and($this->store->validate('orders.cancel', 'bind-xyz', at('12:40'))->outcome)
        ->toBe(ReviewOutcome::InvalidState); // the new one is still only Pending
});

it('keeps the same fingerprint under two capabilities as two distinct requests', function (): void {
    // The execution key is (capability, bindingFingerprint), not the fingerprint alone.
    $this->store->issue(reviewRequest('rev_a', capability: 'orders.cancel', binding: 'bind-shared'));
    $this->store->issue(reviewRequest('rev_b', capability: 'orders.refund', binding: 'bind-shared'));

    expect($this->store->find('rev_a')?->capability)->toBe('orders.cancel')
        ->and($this->store->find('rev_b')?->capability)->toBe('orders.refund')
        ->and($this->store->find('rev_a')?->id)->not->toBe($this->store->find('rev_b')?->id);
});

it('never overwrites an unrelated request when a new binding reuses an existing id', function (): void {
    $this->store->issue(reviewRequest('rev_1', binding: 'bind-abc'));

    // Same id, different binding: a store keyed only on binding would silently clobber rev_1. The
    // full-snapshot check proves not one field of the original moved.
    refusedWithoutMutation(
        $this->store,
        fn (): mixed => $this->store->issue(reviewRequest('rev_1', binding: 'bind-xyz')),
        ReviewOutcome::InvalidState,
    );
});

it('never overwrites an existing id when a different capability reuses it under the same binding', function (): void {
    $this->store->issue(reviewRequest('rev_1', capability: 'orders.cancel', binding: 'bind-abc'));

    // Same id, same binding string, DIFFERENT capability: (capability, binding) differs, so it is a
    // distinct binding — but the id already addresses another request and must not be clobbered.
    refusedWithoutMutation(
        $this->store,
        fn (): mixed => $this->store->issue(reviewRequest('rev_1', capability: 'orders.refund', binding: 'bind-abc')),
        ReviewOutcome::InvalidState,
    );
    expect($this->store->find('rev_1')?->capability)->toBe('orders.cancel');
});

// ── find ──────────────────────────────────────────────────────────────────────────────────────

it('returns null for an unknown request id', function (): void {
    expect($this->store->find('nope'))->toBeNull();
});

// ── approve (by request id; no tool-call mismatch dimension) ─────────────────────────────────────

it('approves a pending request, stamping the resolving actor and time', function (): void {
    $this->store->issue(reviewRequest('rev_1'));

    $transition = $this->store->approve('rev_1', 'reviewer-7', at('12:30'));
    $stored = $this->store->find('rev_1');

    expect($transition->outcome)->toBe(ReviewOutcome::Approved)
        ->and($stored?->status)->toBe(ReviewStatus::Approved)
        ->and($stored?->resolvedBy)->toBe('reviewer-7')
        ->and($stored?->resolvedAt)->toEqual(at('12:30'))
        ->and($stored?->consumedAt)->toBeNull();
});

it('reports NotFound approving an unknown request', function (): void {
    $transition = $this->store->approve('nope', 'reviewer-7', at('12:30'));

    expect($transition->outcome)->toBe(ReviewOutcome::NotFound)
        ->and($transition->request)->toBeNull();
});

it('refuses to approve a request that is already approved, preserving the first decision', function (): void {
    seedApproved($this->store); // reviewer-7 @ 12:30

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->approve('rev_1', 'reviewer-8', at('12:31')), ReviewOutcome::InvalidState);
});

it('refuses to approve a rejected request, preserving the rejection', function (): void {
    seedRejected($this->store);

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->approve('rev_1', 'reviewer-7', at('12:31')), ReviewOutcome::InvalidState);
});

it('refuses to approve a consumed request', function (): void {
    seedConsumed($this->store);

    // At 12:50 the request is not yet expired (expiresAt 13:00), so the terminal-state check is what refuses.
    refusedWithoutMutation($this->store, fn (): mixed => $this->store->approve('rev_1', 'reviewer-7', at('12:50')), ReviewOutcome::InvalidState);
});

it('reports Expired, not InvalidState, for a consumed request past its expiry — expiry precedes the terminal-state check', function (): void {
    // House convention (mirrors ApprovalReceiptStore::transitionFailure): expiry is checked before the
    // status, so an expired-and-consumed request refuses as Expired. Pinned so the ordering cannot silently flip.
    seedConsumed($this->store); // expiresAt 13:00, consumed at 12:45

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->approve('rev_1', 'reviewer-7', at('13:00')), ReviewOutcome::Expired);
});

it('reports Expired approving a request past its expiry, leaving it untouched', function (): void {
    $this->store->issue(reviewRequest('rev_1', expiresAt: at('13:00')));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->approve('rev_1', 'reviewer-7', at('13:30')), ReviewOutcome::Expired);
});

it('treats the expiry instant itself as expired when approving (>= boundary)', function (): void {
    $this->store->issue(reviewRequest('rev_1', expiresAt: at('13:00')));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->approve('rev_1', 'reviewer-7', at('13:00')), ReviewOutcome::Expired);
});

// ── reject ────────────────────────────────────────────────────────────────────────────────────

it('rejects a pending request, stamping the resolving actor and time', function (): void {
    $this->store->issue(reviewRequest('rev_1'));

    $transition = $this->store->reject('rev_1', 'reviewer-9', at('12:30'));
    $stored = $this->store->find('rev_1');

    expect($transition->outcome)->toBe(ReviewOutcome::Rejected)
        ->and($stored?->status)->toBe(ReviewStatus::Rejected)
        ->and($stored?->resolvedBy)->toBe('reviewer-9')
        ->and($stored?->resolvedAt)->toEqual(at('12:30'));
});

it('reports NotFound rejecting an unknown request', function (): void {
    expect($this->store->reject('nope', 'reviewer-9', at('12:30'))->outcome)->toBe(ReviewOutcome::NotFound);
});

it('refuses to reject an approved request, preserving the approval', function (): void {
    seedApproved($this->store);

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->reject('rev_1', 'reviewer-9', at('12:31')), ReviewOutcome::InvalidState);
});

it('refuses to reject a consumed request', function (): void {
    seedConsumed($this->store);

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->reject('rev_1', 'reviewer-9', at('12:50')), ReviewOutcome::InvalidState);
});

it('refuses to reject an already-rejected request, preserving the first decision', function (): void {
    seedRejected($this->store); // reviewer-9 @ 12:30

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->reject('rev_1', 'reviewer-8', at('12:31')), ReviewOutcome::InvalidState);
});

it('reports Expired rejecting a request past its expiry, leaving it untouched', function (): void {
    $this->store->issue(reviewRequest('rev_1', expiresAt: at('13:00')));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->reject('rev_1', 'reviewer-9', at('13:30')), ReviewOutcome::Expired);
});

it('treats the expiry instant itself as expired when rejecting (>= boundary)', function (): void {
    $this->store->issue(reviewRequest('rev_1', expiresAt: at('13:00')));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->reject('rev_1', 'reviewer-9', at('13:00')), ReviewOutcome::Expired);
});

// ── validate (execution-side, non-mutating, keyed by capability + binding) ───────────────────────

it('validates an approved request without mutating it, returning canonical material', function (): void {
    $this->store->issue(richReviewRequest());
    $this->store->approve('rev_rich', 'reviewer-7', at('12:30'));

    $transition = $this->store->validate('orders.cancel', 'bind-rich', at('12:40'));

    expect($transition->outcome)->toBe(ReviewOutcome::Approved)
        ->and($transition->request?->id)->toBe('rev_rich')
        ->and($this->store->find('rev_rich')?->status)->toBe(ReviewStatus::Approved); // non-mutating
    expectMaterialPreserved($transition->request);
    expectMaterialPreserved($this->store->find('rev_rich'));
});

it('reports NotFound validating a binding with no request', function (): void {
    expect($this->store->validate('orders.cancel', 'bind-missing', at('12:40'))->outcome)
        ->toBe(ReviewOutcome::NotFound);
});

it('reports NotFound validating the wrong capability for a matching binding, without mutation', function (): void {
    seedApproved($this->store); // orders.cancel / bind-abc, approved

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->validate('orders.refund', 'bind-abc', at('12:40')), ReviewOutcome::NotFound);
});

it('refuses to validate a request that is not yet approved', function (): void {
    $this->store->issue(reviewRequest('rev_1'));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->validate('orders.cancel', 'bind-abc', at('12:40')), ReviewOutcome::InvalidState);
});

it('refuses to validate a rejected request', function (): void {
    seedRejected($this->store);

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->validate('orders.cancel', 'bind-abc', at('12:40')), ReviewOutcome::InvalidState);
});

it('refuses to validate a consumed request', function (): void {
    seedConsumed($this->store);

    // At 12:50 the request is not yet expired, so the terminal-state check refuses.
    refusedWithoutMutation($this->store, fn (): mixed => $this->store->validate('orders.cancel', 'bind-abc', at('12:50')), ReviewOutcome::InvalidState);
});

it('reports Expired validating a consumed request past its expiry — the read path checks expiry first too', function (): void {
    // validate() has its own guard path (separate from approve/reject's), so its expiry-before-status
    // ordering is pinned independently.
    seedConsumed($this->store); // expiresAt 13:00, consumed at 12:45

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->validate('orders.cancel', 'bind-abc', at('13:00')), ReviewOutcome::Expired);
});

it('reports Expired validating an approved-but-lapsed request', function (): void {
    seedApproved($this->store, expiresAt: at('13:00'));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->validate('orders.cancel', 'bind-abc', at('13:30')), ReviewOutcome::Expired);
});

it('treats the expiry instant itself as expired when validating (>= boundary)', function (): void {
    seedApproved($this->store, expiresAt: at('13:00'));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->validate('orders.cancel', 'bind-abc', at('13:00')), ReviewOutcome::Expired);
});

// ── consume (single-use, atomic execution admission) ─────────────────────────────────────────────

it('consumes an approved request once, stamping consumption and retaining the reviewer', function (): void {
    seedApproved($this->store); // reviewer-7 @ 12:30

    $transition = $this->store->consume('orders.cancel', 'bind-abc', at('12:45'));
    $stored = $this->store->find('rev_1');

    expect($transition->outcome)->toBe(ReviewOutcome::Consumed)
        ->and($stored?->status)->toBe(ReviewStatus::Consumed)
        ->and($stored?->consumedAt)->toEqual(at('12:45'))
        ->and($stored?->resolvedBy)->toBe('reviewer-7')
        ->and($stored?->resolvedAt)->toEqual(at('12:30'));
});

it('admits a consumption only once — a second consume is refused', function (): void {
    seedApproved($this->store);
    $this->store->consume('orders.cancel', 'bind-abc', at('12:45'));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->consume('orders.cancel', 'bind-abc', at('12:46')), ReviewOutcome::InvalidState);
});

it('reports NotFound consuming a binding with no request', function (): void {
    expect($this->store->consume('orders.cancel', 'bind-missing', at('12:45'))->outcome)
        ->toBe(ReviewOutcome::NotFound);
});

it('reports NotFound consuming the wrong capability for a matching binding, without mutation', function (): void {
    seedApproved($this->store);

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->consume('orders.refund', 'bind-abc', at('12:45')), ReviewOutcome::NotFound);
});

it('refuses to consume a request that was never approved, leaving it Pending', function (): void {
    $this->store->issue(reviewRequest('rev_1'));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->consume('orders.cancel', 'bind-abc', at('12:45')), ReviewOutcome::InvalidState);
});

it('refuses to consume a rejected request', function (): void {
    seedRejected($this->store);

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->consume('orders.cancel', 'bind-abc', at('12:45')), ReviewOutcome::InvalidState);
});

it('refuses to consume an approved-but-expired request, leaving it Approved', function (): void {
    seedApproved($this->store, expiresAt: at('13:00'));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->consume('orders.cancel', 'bind-abc', at('13:30')), ReviewOutcome::Expired);
});

it('treats the expiry instant itself as expired when consuming (>= boundary)', function (): void {
    seedApproved($this->store, expiresAt: at('13:00'));

    refusedWithoutMutation($this->store, fn (): mixed => $this->store->consume('orders.cancel', 'bind-abc', at('13:00')), ReviewOutcome::Expired);
});

// ── immutable material survives a successful transition ──────────────────────────────────────────

it('preserves all immutable review material when a request is approved', function (): void {
    $this->store->issue(richReviewRequest());

    $transition = $this->store->approve('rev_rich', 'reviewer-7', at('12:30'));

    $stored = $this->store->find('rev_rich');
    expectMaterialPreserved($stored);
    expectMaterialPreserved($transition->request);
    expect($stored?->status)->toBe(ReviewStatus::Approved)
        ->and($stored?->resolvedBy)->toBe('reviewer-7')
        ->and($transition->request?->status)->toBe(ReviewStatus::Approved);
});

it('preserves all immutable review material when a request is rejected', function (): void {
    $this->store->issue(richReviewRequest());

    $transition = $this->store->reject('rev_rich', 'reviewer-9', at('12:30'));

    $stored = $this->store->find('rev_rich');
    expectMaterialPreserved($stored);
    expectMaterialPreserved($transition->request);
    expect($stored?->status)->toBe(ReviewStatus::Rejected)
        ->and($stored?->resolvedBy)->toBe('reviewer-9')
        ->and($transition->request?->status)->toBe(ReviewStatus::Rejected);
});

it('preserves all immutable review material when a request is consumed', function (): void {
    $this->store->issue(richReviewRequest());
    $this->store->approve('rev_rich', 'reviewer-7', at('12:30'));

    $transition = $this->store->consume('orders.cancel', 'bind-rich', at('12:45'));

    $stored = $this->store->find('rev_rich');
    expectMaterialPreserved($stored);
    expectMaterialPreserved($transition->request);
    expect($stored?->status)->toBe(ReviewStatus::Consumed)
        ->and($stored?->resolvedBy)->toBe('reviewer-7')
        ->and($stored?->consumedAt)->toEqual(at('12:45'))
        ->and($transition->request?->status)->toBe(ReviewStatus::Consumed);
});

// ── the store is the contract ────────────────────────────────────────────────────────────────────

it('implements the ReviewRequestStore contract', function (): void {
    expect($this->store)->toBeInstanceOf(ReviewRequestStore::class);
});

// ── helpers ──────────────────────────────────────────────────────────────────────────────────────

function at(string $hhmm): DateTimeImmutable
{
    return new DateTimeImmutable("2026-08-30T{$hhmm}:00+00:00");
}

function reviewRequest(
    string $id,
    string $binding = 'bind-abc',
    string $capability = 'orders.cancel',
    ?DateTimeImmutable $createdAt = null,
    ?DateTimeImmutable $expiresAt = null,
): ReviewRequest {
    return ReviewRequest::pending(
        id: $id,
        capability: $capability,
        bindingFingerprint: $binding,
        approvalContext: ['tenant_id' => 'store-1'],
        createdAt: $createdAt ?? at('12:00'),
        expiresAt: $expiresAt ?? at('13:00'),
        reason: 'A human must review this cancellation.',
    );
}

/** A fully-populated request: every immutable field carries a distinctive value to survive a transition. */
function richReviewRequest(): ReviewRequest
{
    return ReviewRequest::pending(
        id: 'rev_rich',
        capability: 'orders.cancel',
        bindingFingerprint: 'bind-rich',
        approvalContext: ['tenant_id' => 'store-1', 'conversation_id' => 'c-42'],
        createdAt: at('12:00'),
        expiresAt: at('13:00'),
        reason: 'A human must review this cancellation.',
        // Distinctive provenance (not unknown()): a replace() that swaps or drops it must fail the
        // value comparison in expectMaterialPreserved, which non-null alone would not catch.
        provenance: ProposalProvenance::declared(sources: [], undescribedSourceCount: 3, withheldSourceCount: 2),
        approverSummary: new ApproverSummary(content: 'Cancel order 7001 for tenant store-1.', fingerprint: 'summary-fp-1'),
    );
}

/** Asserts a transitioned request kept every non-lifecycle field the record was issued with. */
function expectMaterialPreserved(?ReviewRequest $request): void
{
    expect($request)->not->toBeNull()
        ->and($request?->id)->toBe('rev_rich')
        ->and($request?->capability)->toBe('orders.cancel')
        ->and($request?->bindingFingerprint)->toBe('bind-rich')
        ->and($request?->approvalContext)->toBe(['tenant_id' => 'store-1', 'conversation_id' => 'c-42'])
        ->and($request?->reason)->toBe('A human must review this cancellation.')
        ->and($request?->provenance?->toArray())->toEqual(
            ProposalProvenance::declared(sources: [], undescribedSourceCount: 3, withheldSourceCount: 2)->toArray(),
        )
        ->and($request?->approverSummary?->content)->toBe('Cancel order 7001 for tenant store-1.')
        ->and($request?->approverSummary?->fingerprint)->toBe('summary-fp-1')
        ->and($request?->createdAt)->toEqual(at('12:00'))
        ->and($request?->expiresAt)->toEqual(at('13:00'));
}

/** EVERY field of the record — a refused op must leave the whole thing exactly as it was, not merely
 *  the lifecycle columns; rewriting capability, binding, context, provenance, or a timestamp is a defect. */
function fullSnapshot(?ReviewRequest $request): array
{
    return [
        'id' => $request?->id,
        'capability' => $request?->capability,
        'binding' => $request?->bindingFingerprint,
        'approvalContext' => $request?->approvalContext,
        'reason' => $request?->reason,
        'provenance' => $request?->provenance?->toArray(),
        'summaryContent' => $request?->approverSummary?->content,
        'summaryFingerprint' => $request?->approverSummary?->fingerprint,
        'status' => $request?->status,
        'resolvedBy' => $request?->resolvedBy,
        'resolvedAt' => $request?->resolvedAt?->format(DATE_ATOM),
        'consumedAt' => $request?->consumedAt?->format(DATE_ATOM),
        'createdAt' => $request?->createdAt?->format(DATE_ATOM),
        'expiresAt' => $request?->expiresAt?->format(DATE_ATOM),
    ];
}

/**
 * Runs an operation expected to REFUSE with $expected and proves it left the target request wholly
 * untouched — a store that mutates ANY field then returns a failure outcome fails here.
 */
function refusedWithoutMutation(ReviewRequestStore $store, callable $op, ReviewOutcome $expected, string $id = 'rev_1'): void
{
    $before = fullSnapshot($store->find($id));

    $transition = $op();

    expect($transition->outcome)->toBe($expected)
        ->and(fullSnapshot($store->find($id)))->toEqual($before);
}

function seedApproved(ReviewRequestStore $store, ?DateTimeImmutable $expiresAt = null): void
{
    $store->issue(reviewRequest('rev_1', expiresAt: $expiresAt ?? at('13:00')));
    $store->approve('rev_1', 'reviewer-7', at('12:30'));
}

function seedRejected(ReviewRequestStore $store): void
{
    $store->issue(reviewRequest('rev_1'));
    $store->reject('rev_1', 'reviewer-9', at('12:30'));
}

function seedConsumed(ReviewRequestStore $store): void
{
    seedApproved($store);
    $store->consume('orders.cancel', 'bind-abc', at('12:45'));
}
