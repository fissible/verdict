<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\CanonicalJson;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\RecordDigest;

/**
 * Every stable field carries a distinct value, so a test that changes one and expects a different
 * digest cannot pass by coincidence.
 *
 * @param  array<string, mixed>  $overrides
 */
function digestEvidence(array $overrides = []): DecisionEvidence
{
    $arguments = [
        'envelopeId' => 'envelope-1',
        'capability' => 'orders.cancel',
        'stage' => 'execution_claim',
        'disposition' => 'permit',
        'reason' => 'At-most-once execution claim completed.',
        'argumentFingerprint' => str_repeat('a', 64),
        'idempotencyKey' => 'idem-1',
        'approvalReceiptFingerprint' => str_repeat('b', 64),
        'approvalPhase' => 'consumption',
        'approvalOutcome' => 'consumed',
        'targetPolicy' => 'orders-target',
        'targetStrategy' => 'accept_stale_snapshot',
        'proposalTargetIdentityFingerprint' => str_repeat('c', 64),
        'executionTargetIdentityFingerprint' => str_repeat('d', 64),
        'targetIdentityMatched' => true,
        'rateLimitKeyFingerprint' => str_repeat('e', 64),
        'rateLimitPolicy' => 'orders-per-customer',
        'rateLimitLimit' => 5,
        'rateLimitRemaining' => 4,
        'rateLimitResetAt' => new DateTimeImmutable('2026-08-19T10:00:00+00:00'),
        'executionClaimFingerprint' => str_repeat('f', 64),
        'executionClaimBindingFingerprint' => str_repeat('1', 64),
        'executionClaimPolicy' => 'cancel-once',
        'executionClaimStatus' => 'completed',
        'executionClaimAttempt' => 1,
        'recordedAt' => new DateTimeImmutable('2026-08-19T09:30:00+00:00'),
        'invocationId' => 'invocation-1',
        'toolKind' => 'bound',
        'configurationFingerprint' => str_repeat('2', 64),
        'actorFingerprint' => str_repeat('3', 64),
        'subjectFingerprint' => str_repeat('4', 64),
        'targetSource' => 'proposal',
        'toolDescriptionFingerprint' => str_repeat('5', 64),
        'invocationToolDescriptionFingerprint' => str_repeat('6', 64),
        'toolDescriptionMatched' => true,
    ];

    return new DecisionEvidence(...[...$arguments, ...$overrides]);
}

/** Every field the digest is defined over, with a value that differs from {@see digestEvidence()}'s. */
function digestStableFieldVariants(): array
{
    return [
        'envelopeId' => 'envelope-2',
        'capability' => 'orders.refund',
        'stage' => 'execution',
        'disposition' => 'deny',
        'argumentFingerprint' => str_repeat('9', 64),
        'idempotencyKey' => 'idem-2',
        'approvalReceiptFingerprint' => str_repeat('8', 64),
        'approvalPhase' => 'proposal_validation',
        'approvalOutcome' => 'approved',
        'targetPolicy' => 'other-target',
        'targetStrategy' => 'require_fresh_target',
        'proposalTargetIdentityFingerprint' => str_repeat('7', 64),
        'executionTargetIdentityFingerprint' => str_repeat('6', 64),
        'targetIdentityMatched' => false,
        'rateLimitKeyFingerprint' => str_repeat('5', 64),
        'rateLimitPolicy' => 'other-limit',
        'rateLimitLimit' => 50,
        'rateLimitRemaining' => 40,
        'rateLimitResetAt' => new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
        'executionClaimFingerprint' => str_repeat('4', 64),
        'executionClaimBindingFingerprint' => str_repeat('3', 64),
        'executionClaimPolicy' => 'refund-once',
        'executionClaimStatus' => 'claimed',
        'executionClaimAttempt' => 2,
        'recordedAt' => new DateTimeImmutable('2026-08-19T09:30:01+00:00'),
        'invocationId' => 'invocation-2',
        'toolKind' => 'guarded',
        'configurationFingerprint' => str_repeat('2', 63).'a',
        'actorFingerprint' => str_repeat('3', 63).'a',
        'subjectFingerprint' => str_repeat('4', 63).'a',
        'targetSource' => 'context',
        'toolDescriptionFingerprint' => str_repeat('5', 63).'a',
        'invocationToolDescriptionFingerprint' => str_repeat('6', 63).'a',
        'toolDescriptionMatched' => false,
    ];
}

it('gives two records built from the same stable fields the same digest', function (): void {
    expect(digestEvidence()->recordDigest)->toBe(digestEvidence()->recordDigest);
});

it('changes the digest when any single stable field changes', function (): void {
    $baseline = digestEvidence()->recordDigest;
    $collisions = [];

    foreach (digestStableFieldVariants() as $field => $value) {
        if (digestEvidence([$field => $value])->recordDigest === $baseline) {
            $collisions[] = $field;
        }
    }

    expect($collisions)->toBe([], 'These stable fields do not affect the digest: '.implode(', ', $collisions));
});

/**
 * `reason` is operator-facing and application-controllable — the one free-text field on the record.
 * Folding it in would let an application change a record's identity by rewording a message.
 */
it('does not change the digest when the operator-facing reason changes', function (): void {
    expect(digestEvidence(['reason' => 'Something else entirely.'])->recordDigest)
        ->toBe(digestEvidence()->recordDigest)
        ->and(digestEvidence(['reason' => null])->recordDigest)
        ->toBe(digestEvidence()->recordDigest);
});

/** The scheme is part of the value, so a future canonicalization is additive rather than a re-identity. */
it('stores the digest scheme-tagged', function (): void {
    expect(digestEvidence()->recordDigest)->toStartWith('canonicaljson-sha256:')
        ->and(RecordDigest::SCHEME)->toBe('canonicaljson-sha256');

    [$scheme, $hash] = explode(':', digestEvidence()->recordDigest, 2);

    expect($scheme)->toBe(RecordDigest::SCHEME)
        ->and($hash)->toMatch('/^[a-f0-9]{64}$/');
});

/**
 * Reproducible offline, without Attest and without Verdict's recorder: a third party holding the
 * documented field set and `CanonicalJson` re-derives the same value.
 */
it('is reproducible from the documented field set alone', function (): void {
    $evidence = digestEvidence();

    $recomputed = RecordDigest::SCHEME.':'.hash(
        'sha256',
        CanonicalJson::encode(RecordDigest::stableFields($evidence), 'record digest'),
    );

    expect($recomputed)->toBe($evidence->recordDigest);
});

/**
 * The raw idempotency key never reaches the digest input — the record persists only its
 * fingerprint, so a digest over the raw value could not be recomputed from a stored row, and
 * would put an application-supplied raw value inside the identity. See ADR 0008.
 */
it('digests the idempotency key by fingerprint, never by raw value', function (): void {
    $fields = RecordDigest::stableFields(digestEvidence());
    $encoded = CanonicalJson::encode($fields, 'record digest');

    expect($encoded)->not->toContain('idem-1')
        ->and($fields['idempotency_key_fingerprint'])->toBe(hash('sha256', 'idem-1'));
});

/**
 * `recordedAt` enters the digest at second precision in UTC. The persisted column is a `timestamp`,
 * whose precision differs across supported databases, so a digest over sub-second precision could
 * not be re-derived from the row Verdict itself wrote.
 */
it('normalizes recordedAt to UTC seconds so a persisted row can reproduce the digest', function (): void {
    $utc = new DateTimeImmutable('2026-08-19T09:30:00+00:00');
    $sameInstantElsewhere = new DateTimeImmutable('2026-08-19T11:30:00+02:00');
    $withMicroseconds = new DateTimeImmutable('2026-08-19T09:30:00.123456+00:00');

    expect(digestEvidence(['recordedAt' => $sameInstantElsewhere])->recordDigest)
        ->toBe(digestEvidence(['recordedAt' => $utc])->recordDigest, 'The same instant in another zone is the same record.')
        ->and(digestEvidence(['recordedAt' => $withMicroseconds])->recordDigest)
        ->toBe(digestEvidence(['recordedAt' => $utc])->recordDigest, 'Sub-second precision does not survive persistence.');
});

/** The digest introduces no new raw value: every input is an existing fingerprint, enum, or scalar. */
it('introduces no raw argument or identity value into the digest input', function (): void {
    $encoded = CanonicalJson::encode(RecordDigest::stableFields(digestEvidence()), 'record digest');

    expect($encoded)->not->toContain('idem-1');

    foreach (['argument_fingerprint', 'actor_fingerprint', 'subject_fingerprint', 'configuration_fingerprint'] as $field) {
        expect(RecordDigest::stableFields(digestEvidence())[$field])->toMatch('/^[a-f0-9]{64}$/');
    }
});
