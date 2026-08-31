<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Reviews\ApproverSummary;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Reviews\ReviewTransition;

// ADR 0035 §3/§6 — the value layer of the asynchronous review lane: a durable ReviewRequest record
// and the store's transition vocabulary. These types are the SEPARATE primitive of §2 (they are not
// the approval receipt reused), but they deliberately mirror the confirmation lane's value objects
// (ApprovalReceiptStatus / ApprovalReceipt / ApprovalOutcome / ApprovalTransition) so the two lanes
// read alike. This slice is the value layer ONLY — the store behaviour (issue/approve/consume, its
// idempotency and single-use) is a later slice; here we pin the shapes everything else binds to.

// ── ReviewStatus ────────────────────────────────────────────────────────────────────────────────

it('names exactly the four lifecycle states with stable persisted values', function (): void {
    // These string values are written to a store; a typo silently corrupts durable state.
    $map = [];
    foreach (ReviewStatus::cases() as $case) {
        $map[$case->name] = $case->value;
    }

    expect($map)->toBe([
        'Pending' => 'pending',
        'Approved' => 'approved',
        'Rejected' => 'rejected',
        'Consumed' => 'consumed',
    ]);
});

// ── ReviewOutcome ───────────────────────────────────────────────────────────────────────────────

it('locks the complete transition-outcome vocabulary and its persisted values', function (): void {
    // The full ordered name->value contract. A store returns these; adding, removing, or renaming a
    // case silently changes what the gate and reader read against. Mismatch is retained (mirroring
    // the confirmation lane): a review is id-addressed, but the execution gate still validates a
    // request's binding, and whether a wrong-bound request is NotFound or Mismatch is the store
    // slice's call — the vocabulary must leave that decision open, not foreclose it.
    $map = [];
    foreach (ReviewOutcome::cases() as $case) {
        $map[$case->name] = $case->value;
    }

    expect($map)->toBe([
        'Issued' => 'issued',
        'Existing' => 'existing',
        'Approved' => 'approved',
        'Rejected' => 'rejected',
        'Consumed' => 'consumed',
        'NotFound' => 'not_found',
        'Mismatch' => 'mismatch',
        'Expired' => 'expired',
        'InvalidState' => 'invalid_state',
        'Unauthorized' => 'unauthorized',
    ]);
});

it('treats every completed transition as succeeded and every refusal as not', function (): void {
    // succeeded() means "the store completed a valid transition", NOT "the action is permitted":
    // a Rejected review is a successful transition to a refused decision. This split is what the
    // gate and the reader read against, so it must be exact for every outcome.
    expect(ReviewOutcome::Issued->succeeded())->toBeTrue()
        ->and(ReviewOutcome::Existing->succeeded())->toBeTrue()
        ->and(ReviewOutcome::Approved->succeeded())->toBeTrue()
        ->and(ReviewOutcome::Rejected->succeeded())->toBeTrue()
        ->and(ReviewOutcome::Consumed->succeeded())->toBeTrue()
        ->and(ReviewOutcome::NotFound->succeeded())->toBeFalse()
        ->and(ReviewOutcome::Mismatch->succeeded())->toBeFalse()
        ->and(ReviewOutcome::Expired->succeeded())->toBeFalse()
        ->and(ReviewOutcome::InvalidState->succeeded())->toBeFalse()
        ->and(ReviewOutcome::Unauthorized->succeeded())->toBeFalse();
});

// ── ReviewTransition ────────────────────────────────────────────────────────────────────────────

it('pairs an outcome with the request it concerns, or with none', function (): void {
    $request = pendingReviewRequest();

    $with = ReviewTransition::to(ReviewOutcome::Existing, $request);
    $without = ReviewTransition::to(ReviewOutcome::NotFound);

    expect($with->outcome)->toBe(ReviewOutcome::Existing)
        ->and($with->request)->toBe($request)
        ->and($with->succeeded())->toBeTrue()
        ->and($without->outcome)->toBe(ReviewOutcome::NotFound)
        ->and($without->request)->toBeNull()
        ->and($without->succeeded())->toBeFalse();
});

// ── ReviewRequest ───────────────────────────────────────────────────────────────────────────────

it('opens a fresh request as Pending with nothing resolved or consumed', function (): void {
    $created = new DateTimeImmutable('2026-08-30T12:00:00+00:00');
    $expires = new DateTimeImmutable('2026-08-30T13:00:00+00:00');

    $request = ReviewRequest::pending(
        id: 'rev_01',
        capability: 'orders.cancel',
        bindingFingerprint: 'bind-abc',
        approvalContext: ['tenant_id' => 'store-1'],
        createdAt: $created,
        expiresAt: $expires,
        reason: 'A human must review this cancellation.',
    );

    expect($request->id)->toBe('rev_01')
        ->and($request->capability)->toBe('orders.cancel')
        ->and($request->bindingFingerprint)->toBe('bind-abc')
        ->and($request->approvalContext)->toBe(['tenant_id' => 'store-1'])
        ->and($request->reason)->toBe('A human must review this cancellation.')
        ->and($request->status)->toBe(ReviewStatus::Pending)
        ->and($request->createdAt)->toEqual($created)
        ->and($request->expiresAt)->toEqual($expires)
        ->and($request->resolvedBy)->toBeNull()
        ->and($request->resolvedAt)->toBeNull()
        ->and($request->consumedAt)->toBeNull();
});

it('carries an immutable approver summary with its content and fingerprint', function (): void {
    // ADR 0035 §3 records the #306 approver summary immutably with its fingerprint. Minimal here —
    // content + fingerprint; #306 enriches ApproverSummary (schema/version, release result) WITHOUT
    // touching ReviewRequest, which is exactly why the record carries the typed field, not a bare string.
    $summary = new ApproverSummary(
        content: 'Cancel order 7001 for tenant store-1.',
        fingerprint: 'summary-fp-123',
    );

    expect($summary->content)->toBe('Cancel order 7001 for tenant store-1.')
        ->and($summary->fingerprint)->toBe('summary-fp-123')
        ->and(fn (): mixed => $summary->fingerprint = 'tampered')->toThrow(Error::class);
});

it('materializes provenance and the approver summary at issuance, or records neither', function (): void {
    // ADR 0035 §3: the provenance disclosure (ADR 0026) and the #306 approver summary are recorded
    // immutably when the request is issued. Their PRODUCERS land in later slices, but the record must
    // carry the fields NOW so the durable shape and the DB-store migration do not churn afterward.
    // Null on both = never materialized (mirrors ApprovalReceipt's provenance).
    $provenance = ProposalProvenance::unknown();
    $summary = new ApproverSummary(content: 'Cancel order 7001.', fingerprint: 'summary-fp-123');

    $carried = ReviewRequest::pending(
        id: 'rev_prov',
        capability: 'orders.cancel',
        bindingFingerprint: 'bind-abc',
        approvalContext: [],
        createdAt: new DateTimeImmutable('2026-08-30T12:00:00+00:00'),
        expiresAt: new DateTimeImmutable('2026-08-30T13:00:00+00:00'),
        provenance: $provenance,
        approverSummary: $summary,
    );

    $bare = pendingReviewRequest();

    expect($carried->provenance)->toBe($provenance)
        ->and($carried->approverSummary)->toBe($summary)
        ->and($carried->approverSummary->fingerprint)->toBe('summary-fp-123')
        ->and($bare->provenance)->toBeNull()
        ->and($bare->approverSummary)->toBeNull();
});

it('uses one unified resolution actor and timestamp, not an approve/reject split', function (): void {
    // ADR 0035 §4: a review has a single resolution (approve XOR reject), so resolvedBy/resolvedAt
    // carry it and the status says which — unlike the receipt's separate approvedBy/rejectedBy.
    $resolved = new DateTimeImmutable('2026-08-30T12:30:00+00:00');

    $approved = resolvedReviewRequest(ReviewStatus::Approved, 'reviewer-7', $resolved);
    $rejected = resolvedReviewRequest(ReviewStatus::Rejected, 'reviewer-9', $resolved);

    expect($approved->status)->toBe(ReviewStatus::Approved)
        ->and($approved->resolvedBy)->toBe('reviewer-7')
        ->and($approved->resolvedAt)->toEqual($resolved)
        ->and($approved->consumedAt)->toBeNull()
        ->and($rejected->status)->toBe(ReviewStatus::Rejected)
        ->and($rejected->resolvedBy)->toBe('reviewer-9')
        ->and($rejected->resolvedAt)->toEqual($resolved);
});

it('retains the resolution actor and timestamp after consumption', function (): void {
    // Consumed is a lifecycle state reached from Approved; the record must still show WHO approved
    // it and WHEN, alongside the consumption instant — the reviewer is not erased by execution.
    $resolved = new DateTimeImmutable('2026-08-30T12:30:00+00:00');
    $consumed = new DateTimeImmutable('2026-08-30T12:45:00+00:00');

    $request = resolvedReviewRequest(ReviewStatus::Consumed, 'reviewer-7', $resolved, $consumed);

    expect($request->status)->toBe(ReviewStatus::Consumed)
        ->and($request->resolvedBy)->toBe('reviewer-7')
        ->and($request->resolvedAt)->toEqual($resolved)
        ->and($request->consumedAt)->toEqual($consumed);
});

it('compares its stored expiry independent of lifecycle state', function (): void {
    // isExpiredAt reads the stored expiresAt, never the status: an Approved (or any) request is
    // expirable exactly as a Pending one is. Guards against tying expiry to a single state.
    $expires = new DateTimeImmutable('2026-08-30T13:00:00+00:00');
    $approved = resolvedReviewRequest(
        ReviewStatus::Approved,
        'reviewer-7',
        new DateTimeImmutable('2026-08-30T12:30:00+00:00'),
        expiresAt: $expires,
    );

    expect($approved->isExpiredAt(new DateTimeImmutable('2026-08-30T12:59:59+00:00')))->toBeFalse()
        ->and($approved->isExpiredAt(new DateTimeImmutable('2026-08-30T13:00:00+00:00')))->toBeTrue();
});

it('is expired only at or after its expiry instant', function (): void {
    $request = pendingReviewRequest(expiresAt: new DateTimeImmutable('2026-08-30T13:00:00+00:00'));

    expect($request->isExpiredAt(new DateTimeImmutable('2026-08-30T12:59:59+00:00')))->toBeFalse()
        ->and($request->isExpiredAt(new DateTimeImmutable('2026-08-30T13:00:00+00:00')))->toBeTrue()
        ->and($request->isExpiredAt(new DateTimeImmutable('2026-08-30T13:00:01+00:00')))->toBeTrue();
});

it('distinguishes a never-captured context (null) from an application that supplied none ([])', function (): void {
    // Mirrors ApprovalReceipt: null means Verdict never captured it; [] means the application bound
    // no identifiers. The authorizer keys on this, so the two must never collapse together.
    $never = pendingReviewRequest(approvalContext: null);
    $none = pendingReviewRequest(approvalContext: []);

    expect($never->approvalContext)->toBeNull()
        ->and($none->approvalContext)->toBe([]);
});

it('is an immutable record — a resolved field cannot be reassigned after construction', function (): void {
    // The record is durable evidence; a mutable status/actor would let a consumer rewrite a decision.
    $request = pendingReviewRequest();

    expect(fn (): mixed => $request->status = ReviewStatus::Approved)->toThrow(Error::class);
});

// ── helpers ──────────────────────────────────────────────────────────────────────────────────────

/** @param  ?array<string, string|int>  $approvalContext */
function pendingReviewRequest(
    ?array $approvalContext = ['tenant_id' => 'store-1'],
    ?DateTimeImmutable $expiresAt = null,
): ReviewRequest {
    return ReviewRequest::pending(
        id: 'rev_fixture',
        capability: 'orders.cancel',
        bindingFingerprint: 'bind-abc',
        approvalContext: $approvalContext,
        createdAt: new DateTimeImmutable('2026-08-30T12:00:00+00:00'),
        expiresAt: $expiresAt ?? new DateTimeImmutable('2026-08-30T13:00:00+00:00'),
        reason: 'A human must review this cancellation.',
    );
}

function resolvedReviewRequest(
    ReviewStatus $status,
    string $resolvedBy,
    DateTimeImmutable $resolvedAt,
    ?DateTimeImmutable $consumedAt = null,
    ?DateTimeImmutable $expiresAt = null,
): ReviewRequest {
    return new ReviewRequest(
        id: 'rev_resolved',
        capability: 'orders.cancel',
        bindingFingerprint: 'bind-abc',
        approvalContext: [],
        provenance: null,
        approverSummary: null,
        status: $status,
        reason: null,
        createdAt: new DateTimeImmutable('2026-08-30T12:00:00+00:00'),
        expiresAt: $expiresAt ?? new DateTimeImmutable('2026-08-30T13:00:00+00:00'),
        resolvedBy: $resolvedBy,
        resolvedAt: $resolvedAt,
        consumedAt: $consumedAt,
    );
}
