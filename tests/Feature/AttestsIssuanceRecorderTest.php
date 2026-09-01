<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\AttestsIssuance;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Support\ApproverSummary;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Fissible\Verdict\Tests\Support\FlakyChainStore;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

// ADR 0038 §5 — the strict, issuance-blocking attest step is a SEPARATE guarantee from the observational evidence
// stream. Only a recorder that can actually anchor to a tamper-evident chain implements AttestsIssuance; its append
// is SYNCHRONOUS and THROWS on failure REGARDLESS of the recorder's global on_failure posture — a strict capability
// must fail closed even in a deployment whose ordinary evidence writes are configured to alert-and-continue.

function makeAttestIssuanceRecorder(object $store, string $onFailure = 'alert'): AttestEvidenceRecorder
{
    return new AttestEvidenceRecorder(
        attest: AttestFixture::registry($store),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        onFailure: $onFailure,
        baseDelayMs: 1,
    );
}

function issuanceSummary(): ApproverSummary
{
    return new ApproverSummary('Cancel order #9001', hash('sha256', 'Cancel order #9001'));
}

// ── only an attest-capable recorder carries the contract ──────────────────────────────────────────────

it('is implemented by the attest recorder and by none of the non-attesting recorders', function (): void {
    expect(makeAttestIssuanceRecorder(AttestFixture::store()))->toBeInstanceOf(AttestsIssuance::class)
        ->and(new NullEvidenceRecorder)->not->toBeInstanceOf(AttestsIssuance::class)
        ->and(new InMemoryEvidenceRecorder)->not->toBeInstanceOf(AttestsIssuance::class)
        ->and(new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection()))->not->toBeInstanceOf(AttestsIssuance::class);
});

// ── a successful synchronous append anchors the summary fingerprint to the chain ───────────────────────

it('appends the attested-issuance record to the chain, keyed on the identity anchor', function (): void {
    $store = AttestFixture::store();
    $summary = issuanceSummary();

    makeAttestIssuanceRecorder($store)->attestIssuedSummary(ApprovalLane::Confirmation, str_repeat('a', 64), $summary);

    $tail = $store->tail('verdict');
    expect($tail)->not->toBeNull()
        ->and($tail->envelope->type)->toBe('verdict.attested_issuance')
        ->and($tail->envelope->correlation)->toBe(str_repeat('a', 64))
        // The payload is EXACTLY these three fingerprint-only fields — no raw summary content, no extras.
        // (Attest canonicalises key order on readback, so this is a set equality.)
        ->and($tail->envelope->payload)->toEqualCanonicalizing([
            'lane' => 'confirmation',
            'identity_fingerprint' => str_repeat('a', 64),
            'summary_fingerprint' => $summary->fingerprint,
        ]);
});

it('encodes a review-lane append correctly', function (): void {
    $store = AttestFixture::store();
    $summary = issuanceSummary();

    makeAttestIssuanceRecorder($store)->attestIssuedSummary(ApprovalLane::Review, str_repeat('c', 64), $summary);

    $tail = $store->tail('verdict');
    expect($tail->envelope->type)->toBe('verdict.attested_issuance')
        ->and($tail->envelope->payload)->toEqualCanonicalizing([
            'lane' => 'review',
            'identity_fingerprint' => str_repeat('c', 64),
            'summary_fingerprint' => $summary->fingerprint,
        ]);
});

// ── the append THROWS on failure, even when the recorder's global posture is 'alert' ───────────────────

it('throws when the chain append fails, regardless of the global alert posture', function (): void {
    // A chain store that fails every append; the recorder is configured 'alert' (its ordinary writes would swallow).
    $flaky = new FlakyChainStore(AttestFixture::store(), 10);

    expect(fn () => makeAttestIssuanceRecorder($flaky, onFailure: 'alert')
        ->attestIssuedSummary(ApprovalLane::Review, str_repeat('b', 64), issuanceSummary()))
        ->toThrow(Throwable::class);
});
