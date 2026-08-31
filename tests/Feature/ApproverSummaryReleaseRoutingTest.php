<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\ApproverSummaryDiagnostic;
use Fissible\Verdict\Approvals\ApproverSummaryMaterializer;
use Fissible\Verdict\Approvals\ApproverSummaryRelease;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Support\ApproverSummary;
use Fissible\Verdict\VerdictManager;

/** The most recent context-release evidence record, or null when the recorder is not in-memory / empty. */
function lastReleaseEvidence(): ?object
{
    $recorder = app(EvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return null;
    }

    $releases = $recorder->releases();

    return $releases === [] ? null : $releases[array_key_last($releases)];
}

// ADR 0038 §2/§3 — an app-authored approver-summary candidate is not released by the act of authoring it.
// Slice 4 routes the candidate through the SAME ADR 0008 approver-audience release path the provenance
// disclosure uses (ApproverAudience::source()/destination()), producing a TYPED release state:
//   - Released      — a VALID candidate that policy PERMITS; content + fingerprint present.
//   - NotReleased   — no releasable candidate: none authored, OR one that fails the §2 display contract.
//                     Carries a typed local diagnostic (NoCandidate / DisplayContractViolation).
//   - ReleaseDenied — a VALID candidate that policy WITHHELD. Policy is the SOLE driver of this state.
// The display contract is enforced at the value boundary WITHOUT transforming the content: no truncation,
// no sanitisation. An invalid candidate is NotReleased, never ReleaseDenied, and never reaches the policy.
// Absence stores no content and never records the rejected raw text. This is the confirmation lane's
// materialiser; the review lane reuses the same service later (slice 7).

/** The materialiser under test, wired to the container's release stack (which reads the registry below). */
function releaseRoutingMaterializer(): ApproverSummaryMaterializer
{
    return new ApproverSummaryMaterializer(app(ContextReleaseManager::class));
}

/** Register an approver-audience policy that PERMITS the summary's classification (Internal / Untrusted). */
function permitApproverSummaries(): void
{
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Untrusted, Trust::Trusted),
    );
}

// ── a valid candidate is Released only when policy permits ─────────────────────────────────────────

it('releases a valid candidate through the approver-audience policy that permits it', function (): void {
    permitApproverSummaries();

    $result = releaseRoutingMaterializer()->materialize('Cancel order #9001');

    expect($result->release)->toBe(ApproverSummaryRelease::Released)
        ->and($result->summary)->toBeInstanceOf(ApproverSummary::class)
        ->and($result->summary->content)->toBe('Cancel order #9001')
        ->and($result->summary->fingerprint)->toBe(hash('sha256', 'Cancel order #9001'))
        ->and($result->diagnostic)->toBeNull();
});

it('stores the candidate verbatim — no truncation, no sanitisation of a released summary', function (): void {
    permitApproverSummaries();
    // Angle brackets and markup are NOT store-time violations: ADR 0038 §2 stores plain text and each
    // RENDERER escapes at render time. The stored content must be the app's bytes, unaltered.
    $candidate = 'Cancel <b>order</b> #9001 — refund $5 & notify a@b.co';

    $result = releaseRoutingMaterializer()->materialize($candidate);

    expect($result->release)->toBe(ApproverSummaryRelease::Released)
        ->and($result->summary->content)->toBe($candidate) // byte-for-byte, no escaping applied at store time
        ->and($result->summary->fingerprint)->toBe(hash('sha256', $candidate));
});

it('releases a valid candidate with surrounding whitespace, stored unchanged — trim is only the empty-check', function (): void {
    permitApproverSummaries();
    // Surrounding spaces are not control characters and are not a violation. The empty-check trims to DECIDE
    // releasability; storage keeps the app's bytes, so a released summary is never trimmed.
    $candidate = '   Cancel order #9001   ';

    $result = releaseRoutingMaterializer()->materialize($candidate);

    expect($result->release)->toBe(ApproverSummaryRelease::Released)
        ->and($result->summary->content)->toBe($candidate) // NOT trimmed
        ->and($result->summary->fingerprint)->toBe(hash('sha256', $candidate));
});

// ── policy is the SOLE driver of ReleaseDenied ─────────────────────────────────────────────────────

it('denies release of a valid candidate when a registered policy withholds it', function (): void {
    // A policy exists for the route but only for the TRUSTED class; the summary is classified UNTRUSTED,
    // so this valid candidate is withheld — ReleaseDenied, and the raw content is NOT retained.
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Trusted),
    );

    $result = releaseRoutingMaterializer()->materialize('Cancel order #9001');

    expect($result->release)->toBe(ApproverSummaryRelease::ReleaseDenied)
        ->and($result->summary)->toBeNull() // denied content is never retained (ADR 0038 §3/§4)
        ->and($result->diagnostic)->toBeNull(); // the release STATE carries denial; no display diagnostic
});

it('denies release of a valid candidate when no approver-audience policy is registered', function (): void {
    // ADR 0008 discipline: nothing travels to a new audience without an explicit policy. With no policy
    // the approver-audience route does not permit, so a valid candidate is withheld — ReleaseDenied,
    // distinct from NotReleased ("no releasable candidate was produced").
    $result = releaseRoutingMaterializer()->materialize('Cancel order #9001');

    expect($result->release)->toBe(ApproverSummaryRelease::ReleaseDenied)
        ->and($result->summary)->toBeNull()
        ->and($result->diagnostic)->toBeNull();
});

// ── the classification is fixed at (Internal, Untrusted): a policy for a DIFFERENT class does not permit ─

it('classifies the summary as Untrusted — a Trusted-only policy does not release it', function (): void {
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal, DataClass::PII, DataClass::Sensitive, DataClass::Public)
            ->whenTrustIs(Trust::Trusted), // every data class, but Trusted trust only
    );

    expect(releaseRoutingMaterializer()->materialize('Cancel order #9001')->release)
        ->toBe(ApproverSummaryRelease::ReleaseDenied);
});

it('classifies the summary as Internal — a policy that omits Internal does not release it', function (): void {
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Public, DataClass::PII, DataClass::Sensitive) // every class EXCEPT Internal
            ->whenTrustIs(Trust::Untrusted, Trust::Trusted),
    );

    expect(releaseRoutingMaterializer()->materialize('Cancel order #9001')->release)
        ->toBe(ApproverSummaryRelease::ReleaseDenied);
});

// ── no candidate → NotReleased + NoCandidate (never reaches the policy) ─────────────────────────────

it('reports NotReleased with a NoCandidate diagnostic when no candidate was authored', function (): void {
    permitApproverSummaries(); // even with a permitting policy, a null candidate is not "denied" — it never existed

    $result = releaseRoutingMaterializer()->materialize(null);

    expect($result->release)->toBe(ApproverSummaryRelease::NotReleased)
        ->and($result->summary)->toBeNull()
        ->and($result->diagnostic)->toBe(ApproverSummaryDiagnostic::NoCandidate);
});

// ── display-contract violations → NotReleased + DisplayContractViolation (NOT ReleaseDenied) ────────

it('reports NotReleased for an empty or whitespace-only candidate, without consulting policy', function (string $candidate): void {
    permitApproverSummaries(); // a permitting policy is present; the invalid candidate must STILL be NotReleased

    $result = releaseRoutingMaterializer()->materialize($candidate);

    expect($result->release)->toBe(ApproverSummaryRelease::NotReleased)
        ->and($result->summary)->toBeNull()
        ->and($result->diagnostic)->toBe(ApproverSummaryDiagnostic::DisplayContractViolation);
})->with(['empty' => '', 'spaces' => '   ', 'tabnl' => "\t\n "]);

it('reports NotReleased for a candidate that exceeds the size bound, without transforming it', function (): void {
    permitApproverSummaries();
    $tooLong = str_repeat('a', ApproverSummaryMaterializer::MAX_CONTENT_BYTES + 1);

    $result = releaseRoutingMaterializer()->materialize($tooLong);

    expect($result->release)->toBe(ApproverSummaryRelease::NotReleased)
        ->and($result->summary)->toBeNull() // NOT truncated-then-released
        ->and($result->diagnostic)->toBe(ApproverSummaryDiagnostic::DisplayContractViolation);
});

it('releases a candidate exactly at the size bound — the bound is inclusive', function (): void {
    permitApproverSummaries();
    $atBound = str_repeat('a', ApproverSummaryMaterializer::MAX_CONTENT_BYTES);

    $result = releaseRoutingMaterializer()->materialize($atBound);

    expect($result->release)->toBe(ApproverSummaryRelease::Released)
        ->and($result->summary->content)->toBe($atBound);
});

it('bounds by BYTE length, not character count', function (): void {
    permitApproverSummaries();
    // A multibyte string whose CHARACTER count is under the bound but whose BYTE length exceeds it.
    $multibyte = str_repeat('é', ApproverSummaryMaterializer::MAX_CONTENT_BYTES); // 2 bytes each → over the byte bound
    $result = releaseRoutingMaterializer()->materialize($multibyte);

    expect(mb_strlen($multibyte))->toBeLessThanOrEqual(ApproverSummaryMaterializer::MAX_CONTENT_BYTES)
        ->and(strlen($multibyte))->toBeGreaterThan(ApproverSummaryMaterializer::MAX_CONTENT_BYTES)
        ->and($result->release)->toBe(ApproverSummaryRelease::NotReleased)
        ->and($result->summary)->toBeNull()
        // an over-byte candidate is a display-contract failure, NOT "no candidate authored"
        ->and($result->diagnostic)->toBe(ApproverSummaryDiagnostic::DisplayContractViolation);
});

it('reports NotReleased for a candidate containing control characters', function (string $candidate): void {
    permitApproverSummaries();

    $result = releaseRoutingMaterializer()->materialize($candidate);

    expect($result->release)->toBe(ApproverSummaryRelease::NotReleased)
        ->and($result->summary)->toBeNull()
        ->and($result->diagnostic)->toBe(ApproverSummaryDiagnostic::DisplayContractViolation);
})->with(function (): array {
    // Every C0 control byte (0x00–0x1F) AND DEL (0x7F) is forbidden — no sampling, so no false-green holes
    // (vertical tab, form feed, carriage return, 0x1F, …). Newline and tab are included: the label is one line.
    $cases = [];

    foreach ([...range(0x00, 0x1F), 0x7F] as $byte) {
        $cases['byte_0x'.str_pad(dechex($byte), 2, '0', STR_PAD_LEFT)] = 'Cancel order'.chr($byte).'#9001';
    }

    return $cases;
});

// ── an invalid candidate short-circuits BEFORE the policy: it is never a ReleaseDenied ──────────────

it('never denies an invalid candidate — the display contract is checked before policy', function (): void {
    // No policy is registered, so a VALID candidate here would be ReleaseDenied (see the no-policy test).
    // An INVALID candidate must resolve as NotReleased regardless — the contract failure is decided at the
    // value boundary and never becomes a policy-withholding outcome.
    $result = releaseRoutingMaterializer()->materialize("bad\x00candidate");

    expect($result->release)->toBe(ApproverSummaryRelease::NotReleased)
        ->and($result->diagnostic)->toBe(ApproverSummaryDiagnostic::DisplayContractViolation);
});

// ── the diagnostic enum is the typed local vocabulary ──────────────────────────────────────────────

it('defines the typed local diagnostics', function (): void {
    expect(ApproverSummaryDiagnostic::cases())
        ->toBe([ApproverSummaryDiagnostic::NoCandidate, ApproverSummaryDiagnostic::DisplayContractViolation]);
});

// ── the materialiser routes through ContextReleaseManager::release() with the fixed classification ─

it('routes a permitted summary through ContextReleaseManager with the fixed approver-audience classification', function (): void {
    permitApproverSummaries();

    releaseRoutingMaterializer()->materialize('Cancel order #9001');

    // The release-manager records ContextReleaseEvidence as a side effect of release(); its presence and
    // fields prove the materialiser actually travelled the ADR 0008 route rather than reimplementing policy.
    $evidence = lastReleaseEvidence();
    expect($evidence)->not->toBeNull()
        ->and($evidence->source)->toBe(ApproverAudience::source()->identity())
        ->and($evidence->destination)->toBe(ApproverAudience::destination()->identity())
        ->and($evidence->trust)->toBe(Trust::Untrusted)   // the summary's fixed classification…
        ->and($evidence->dataClass)->toBe(DataClass::Internal) // …pinned in the release route itself
        ->and($evidence->disposition)->toBe('permit');
});

it('records a deny release-evidence when policy withholds the summary', function (): void {
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Trusted), // does not cover the Untrusted summary
    );

    $result = releaseRoutingMaterializer()->materialize('Cancel order #9001');

    $evidence = lastReleaseEvidence();
    expect($result->release)->toBe(ApproverSummaryRelease::ReleaseDenied)
        ->and($evidence)->not->toBeNull()
        ->and($evidence->disposition)->toBe('deny')
        ->and($evidence->trust)->toBe(Trust::Untrusted)
        ->and($evidence->dataClass)->toBe(DataClass::Internal);
});

it('does not consult the release route for a candidate rejected at the value boundary', function (?string $candidate): void {
    permitApproverSummaries();
    $before = app(EvidenceRecorder::class) instanceof InMemoryEvidenceRecorder
        ? count(app(EvidenceRecorder::class)->releases())
        : 0;

    releaseRoutingMaterializer()->materialize($candidate);

    // A null or contract-invalid candidate short-circuits BEFORE the policy: no release is attempted, so no
    // new release-evidence appears. This is what keeps an invalid candidate NotReleased and never ReleaseDenied.
    $after = app(EvidenceRecorder::class) instanceof InMemoryEvidenceRecorder
        ? count(app(EvidenceRecorder::class)->releases())
        : 0;
    expect($after)->toBe($before);
})->with(['no-candidate' => null, 'contract-invalid' => "bad\x00candidate"]);
