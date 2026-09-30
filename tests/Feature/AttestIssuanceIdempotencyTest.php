<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Support\ApproverSummary;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

// #535 B1 (codex retro-review finding 4.2): issuance attestation is NOT idempotent. attestIssuedSummary()
// appends unconditionally, and attest's EvidenceChain::record() never dedups on `correlation` (the
// identity fingerprint) — it is metadata, not a key. Because the attest append happens OUTSIDE verdict's
// DB transaction, a SecurityStateTransaction retry re-runs the $onAdmitted hook and appends a SECOND
// signed "issued" envelope for the SAME identity fingerprint. Masked previously by self-deduplicating
// FAKE attesters. The recorder must be idempotent per (chain, identity fingerprint): before appending,
// consult the PERSISTED chain (ChainStore::readRange — AttestRegistry::store() is public) for an existing
// `verdict.attested_issuance` envelope carrying that correlation and skip the append if present, keeping
// the FIRST. (The concurrent DISTINCT-id review-lane false-attest is a separate concern — issue #544.)

function attIdemRecorder(object $store): AttestEvidenceRecorder
{
    return new AttestEvidenceRecorder(
        attest: AttestFixture::registry($store),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        onFailure: 'alert',
        baseDelayMs: 1,
    );
}

function attIdemSummary(): ApproverSummary
{
    return new ApproverSummary('Cancel order #9001', hash('sha256', 'Cancel order #9001'));
}

/** @return list<object> the signed envelopes on the chain (full range from seq 1) */
function attIdemEnvelopes(object $store): array
{
    return iterator_to_array($store->readRange('verdict', 1), false);
}

function attIdemCount(object $store, string $fingerprint): int
{
    return count(array_filter(
        attIdemEnvelopes($store),
        fn (object $s): bool => $s->envelope->type === 'verdict.attested_issuance'
            && $s->envelope->correlation === $fingerprint,
    ));
}

/** Append an UNRELATED (non-attested-issuance) envelope directly to the same chain. */
function attIdemSeedOther(object $store, string $type, string $correlation): void
{
    AttestFixture::registry($store)->chain('verdict')->record(
        type: $type,
        payload: ['note' => 'unrelated'],
        correlation: $correlation,
    );
}

it('appends at most one attested-issuance per identity fingerprint across an adjacent retry', function (): void {
    $store = AttestFixture::store();
    $fp = str_repeat('a', 64);

    $recorder = attIdemRecorder($store);
    $recorder->attestIssuedSummary(ApprovalLane::Confirmation, $fp, attIdemSummary());
    $recorder->attestIssuedSummary(ApprovalLane::Confirmation, $fp, attIdemSummary());

    expect(attIdemCount($store, $fp))->toBe(1);
});

it('deduplicates a repeat that is NOT at the tail and beyond sequence 1, preserving unrelated envelopes', function (): void {
    // A, B, <unrelated>, A, B — a tail-only or seq-1-only lookup would miss the earlier A/B and re-append.
    $store = AttestFixture::store();
    $a = str_repeat('a', 64);
    $b = str_repeat('b', 64);
    $recorder = attIdemRecorder($store);

    $recorder->attestIssuedSummary(ApprovalLane::Confirmation, $a, attIdemSummary());
    $recorder->attestIssuedSummary(ApprovalLane::Confirmation, $b, attIdemSummary());
    attIdemSeedOther($store, 'verdict.decision', 'unrelated-correlation');
    $recorder->attestIssuedSummary(ApprovalLane::Confirmation, $a, attIdemSummary());
    $recorder->attestIssuedSummary(ApprovalLane::Confirmation, $b, attIdemSummary());

    $unrelated = array_filter(attIdemEnvelopes($store), fn (object $s): bool => $s->envelope->type === 'verdict.decision');
    expect(attIdemCount($store, $a))->toBe(1)
        ->and(attIdemCount($store, $b))->toBe(1)
        ->and($unrelated)->toHaveCount(1); // the unrelated envelope is untouched
});

it('still appends distinct fingerprints under the SAME lane and summary (no over-deduplication)', function (ApprovalLane $lane): void {
    // Holds lane + summary constant so a lane-only or lane+summary dedup cannot pass.
    $store = AttestFixture::store();
    $a = str_repeat('a', 64);
    $b = str_repeat('b', 64);
    $recorder = attIdemRecorder($store);

    $recorder->attestIssuedSummary($lane, $a, attIdemSummary());
    $recorder->attestIssuedSummary($lane, $b, attIdemSummary());

    expect(attIdemCount($store, $a))->toBe(1)
        ->and(attIdemCount($store, $b))->toBe(1);
})->with([
    'confirmation' => ApprovalLane::Confirmation,
    'review' => ApprovalLane::Review,
]);

it('does not suppress a legitimate issuance when another envelope TYPE shares the correlation', function (): void {
    // A correlation-only lookup that ignored the record type would skip this issuance entirely.
    $store = AttestFixture::store();
    $fp = str_repeat('d', 64);

    attIdemSeedOther($store, 'verdict.decision', $fp); // same correlation, different type
    $recorder = attIdemRecorder($store);
    $recorder->attestIssuedSummary(ApprovalLane::Confirmation, $fp, attIdemSummary());
    $recorder->attestIssuedSummary(ApprovalLane::Confirmation, $fp, attIdemSummary());

    $other = array_filter(attIdemEnvelopes($store), fn (object $s): bool => $s->envelope->type === 'verdict.decision' && $s->envelope->correlation === $fp);
    expect(attIdemCount($store, $fp))->toBe(1) // exactly one attested_issuance
        ->and($other)->toHaveCount(1);         // the pre-existing other-type envelope survived
});

it('deduplicates against the PERSISTED chain, not instance-local state (fresh recorder, shared store)', function (): void {
    $store = AttestFixture::store();
    $fp = str_repeat('e', 64);

    attIdemRecorder($store)->attestIssuedSummary(ApprovalLane::Confirmation, $fp, attIdemSummary());
    // A fresh recorder/registry over the SAME store must still see the persisted attestation and skip.
    attIdemRecorder($store)->attestIssuedSummary(ApprovalLane::Confirmation, $fp, attIdemSummary());

    expect(attIdemCount($store, $fp))->toBe(1);
});

it('keeps the FIRST envelope on dedup and treats the fingerprint as the key across lanes', function (): void {
    $store = AttestFixture::store();
    $fp = str_repeat('f', 64);
    $recorder = attIdemRecorder($store);

    $recorder->attestIssuedSummary(ApprovalLane::Confirmation, $fp, attIdemSummary());
    $first = $store->tail('verdict'); // the surviving envelope must remain byte-identical to this

    // A repeat under a DIFFERENT lane is the same idempotency key (chain, fingerprint): skip, keep first.
    $recorder->attestIssuedSummary(ApprovalLane::Review, $fp, attIdemSummary());

    $matching = array_values(array_filter(
        attIdemEnvelopes($store),
        fn (object $s): bool => $s->envelope->type === 'verdict.attested_issuance' && $s->envelope->correlation === $fp,
    ));

    expect($matching)->toHaveCount(1)
        ->and($matching[0]->selfHash())->toBe($first->selfHash())   // the first append survived, unchanged
        ->and($matching[0]->envelope->payload['lane'])->toBe('confirmation'); // not the later 'review'
});
