<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalOperation;
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;

// ADR 0038 §4 — a post-commit OPERATIONAL event, distinct from the execution-side DecisionEvidence stream.
// Each event anchors on an identityFingerprint (sha256 of the receipt/request id) that is ALWAYS present, and
// carries a summaryFingerprint present ONLY when the approver summary was Released (null otherwise). Both are
// SHA-256 fingerprints — 64 lowercase hex — never a raw id or raw summary content. The value is immutable and
// serialises to a stable snake_case shape for the durable/attesting recorders.

function operationEvidence(
    ApprovalLane $lane = ApprovalLane::Confirmation,
    ApprovalOperation $operation = ApprovalOperation::Issued,
    ?string $identityFingerprint = null,
    ?string $summaryFingerprint = null,
    ?string $invocationId = null,
): ApprovalOperationEvidence {
    return new ApprovalOperationEvidence(
        lane: $lane,
        operation: $operation,
        capability: 'orders.cancel',
        identityFingerprint: $identityFingerprint ?? str_repeat('a', 64),
        summaryFingerprint: $summaryFingerprint,
        occurredAt: new DateTimeImmutable('2026-08-31 12:00:00'),
        invocationId: $invocationId,
    );
}

// ── the closed vocabularies (membership + value, order-independent) ────────────────────────────────────

it('defines the lane vocabulary as confirmation and review', function (): void {
    $values = array_map(fn (ApprovalLane $c): string => $c->value, ApprovalLane::cases());

    expect($values)->toEqualCanonicalizing(['confirmation', 'review'])
        ->and(ApprovalLane::Confirmation->value)->toBe('confirmation')
        ->and(ApprovalLane::Review->value)->toBe('review');
});

it('defines the four lifecycle operations', function (): void {
    $values = array_map(fn (ApprovalOperation $c): string => $c->value, ApprovalOperation::cases());

    expect($values)->toEqualCanonicalizing(['issued', 'approved', 'rejected', 'consumed'])
        ->and(ApprovalOperation::Issued->value)->toBe('issued')
        ->and(ApprovalOperation::Approved->value)->toBe('approved')
        ->and(ApprovalOperation::Rejected->value)->toBe('rejected')
        ->and(ApprovalOperation::Consumed->value)->toBe('consumed');
});

// ── the identity anchor is an always-present SHA-256 fingerprint ────────────────────────────────────────

it('carries a valid 64-hex identity fingerprint', function (): void {
    expect(operationEvidence(identityFingerprint: str_repeat('a', 64))->identityFingerprint)->toBe(str_repeat('a', 64));
});

it('refuses a malformed identity fingerprint', function (string $identity): void {
    // The anchor is a sha256 hex digest; anything else is a raw id or a bug — never a valid operational event.
    operationEvidence(identityFingerprint: $identity);
})->throws(InvalidArgumentException::class)->with([
    'empty' => '',
    'too short' => 'a1b2c3',
    'raw-looking id' => 'rcpt_'.str_repeat('a', 59),
    'uppercase' => str_repeat('A', 64),
    'non-hex' => str_repeat('g', 64),
    'too long' => str_repeat('a', 65),
]);

// ── the summary fingerprint is optional, but a SHA-256 fingerprint when present ─────────────────────────

it('allows a null summary fingerprint (the summary was not Released)', function (): void {
    expect(operationEvidence(summaryFingerprint: null)->summaryFingerprint)->toBeNull();
});

it('carries a valid 64-hex summary fingerprint when one was released', function (): void {
    expect(operationEvidence(summaryFingerprint: str_repeat('b', 64))->summaryFingerprint)->toBe(str_repeat('b', 64));
});

it('refuses a malformed non-null summary fingerprint', function (string $summary): void {
    operationEvidence(summaryFingerprint: $summary);
})->throws(InvalidArgumentException::class)->with([
    'empty' => '',
    'too short' => 'b1b2c3',
    'uppercase' => str_repeat('B', 64),
    'non-hex' => str_repeat('z', 64),
    'too long' => str_repeat('b', 65),
]);

// ── stable serialisation for durable / attesting recorders ───────────────────────────────────────────

it('serialises to a stable snake_case shape carrying only fingerprints', function (): void {
    $evidence = operationEvidence(
        lane: ApprovalLane::Review,
        operation: ApprovalOperation::Consumed,
        identityFingerprint: str_repeat('c', 64),
        summaryFingerprint: str_repeat('d', 64),
        invocationId: 'inv-9',
    );

    expect($evidence->toArray())->toBe([
        'lane' => 'review',
        'operation' => 'consumed',
        'capability' => 'orders.cancel',
        'identity_fingerprint' => str_repeat('c', 64),
        'summary_fingerprint' => str_repeat('d', 64),
        'invocation_id' => 'inv-9',
        'occurred_at' => (new DateTimeImmutable('2026-08-31 12:00:00'))->format(DATE_ATOM),
    ])
        ->and(json_decode(json_encode($evidence, JSON_THROW_ON_ERROR), true))->toBe($evidence->toArray());
});
