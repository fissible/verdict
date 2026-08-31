<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\CanonicalJson;
use Fissible\Verdict\Evidence\ClaimType;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\RecordDigest;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

// 6b-2 durable evidence — a review-stage record's outcome (issued / admitted / not-admitted) must be
// DURABLE, not derived only from transient decision metadata. ClaimType keys the Review stage on that
// outcome; RecordDigest documents that claimType is re-derivable from a record's stable fields; and a
// reader reconstructing a persisted row must recover the same claim. So the outcome:
//   • is a stable field, so an issuance and an admission of the SAME request are different content
//     identities and cannot share a digest;
//   • is null for every NON-review record, so folding it in re-identifies nothing already published
//     (no digest scheme bump);
//   • persists as its own column and survives the database round-trip, so ClaimType::for() re-derives
//     ReviewRequestAdmitted from the row alone, never a mislabelled ReviewRequestIssued;
//   • travels in the attest payload the signature covers.
// The fingerprint-only privacy model (6a-2) holds throughout: no raw review-request id is persisted.

const REVIEW_OUTCOME_FINGERPRINT = 'ff00ff00ff00ff00ff00ff00ff00ff00ff00ff00ff00ff00ff00ff00ff00ff00';

// The digest of reviewOutcomeRecord(null, 'execution') computed BEFORE review_outcome became a stable field.
// Option (b) — conditional inclusion — must leave this byte-for-byte identical: a non-review record is never
// re-identified, so no digest scheme bump is needed. A change here means the field folded in unconditionally.
const NON_REVIEW_RECORD_DIGEST = 'canonicaljson-sha256:2df8e4ebaf4c84d1988e93dc2eb7b170942e7edb4423a174e52e761bcc9c3897';

// The digest of a review-stage record with a NULL outcome, computed before review_outcome existed. Inclusion
// gates on stage === Review AND a non-null outcome, so a null (historic) review record is never re-identified.
const REVIEW_NULL_RECORD_DIGEST = 'canonicaljson-sha256:abfcf5f28597a9b9554aed64c7edc67aab37bb15667fa45b4d8ec0b7ce9bc6f6';

/**
 * Rebuild the digest's stable-field map from a persisted row exactly as an offline reader would —
 * review_outcome included ONLY when the column carries a value (the conditional rule), so the same helper
 * reproduces both a review row and a non-review one.
 *
 * @param  array<string, mixed>  $row
 * @return array<string, bool|int|string|null>
 */
function reviewStableFieldsFromRow(array $row): array
{
    $fields = [
        'envelope_id' => $row['correlation_id'],
        'capability' => $row['capability'],
        'stage' => $row['stage'],
        'disposition' => $row['disposition'],
        'recorded_at' => (new DateTimeImmutable($row['recorded_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        'invocation_id' => $row['invocation_id'],
        'tool_kind' => $row['tool_kind'],
        'target_source' => $row['target_source'],
        'configuration_fingerprint' => $row['configuration_fingerprint'],
        'actor_fingerprint' => $row['actor_fingerprint'],
        'subject_fingerprint' => $row['subject_fingerprint'],
        'argument_fingerprint' => $row['argument_fingerprint'],
        'idempotency_key_fingerprint' => $row['idempotency_key_fingerprint'],
        'approval_receipt_fingerprint' => $row['approval_receipt_fingerprint'],
        'review_request_fingerprint' => $row['review_request_fingerprint'],
        'approval_phase' => $row['approval_phase'],
        'approval_outcome' => $row['approval_outcome'],
        'target_policy' => $row['target_policy'],
        'target_strategy' => $row['target_strategy'],
        'proposal_target_identity_fingerprint' => $row['proposal_target_identity_fingerprint'],
        'execution_target_identity_fingerprint' => $row['execution_target_identity_fingerprint'],
        'target_identity_matched' => $row['target_identity_matched'] === null ? null : (bool) $row['target_identity_matched'],
        'rate_limit_key_fingerprint' => $row['rate_limit_key_fingerprint'],
        'rate_limit_policy' => $row['rate_limit_policy'],
        'rate_limit_limit' => $row['rate_limit_limit'],
        'rate_limit_remaining' => $row['rate_limit_remaining'],
        'rate_limit_reset_at' => $row['rate_limit_reset_at'],
        'execution_claim_fingerprint' => $row['execution_claim_fingerprint'],
        'execution_claim_binding_fingerprint' => $row['execution_claim_binding_fingerprint'],
        'execution_claim_policy' => $row['execution_claim_policy'],
        'execution_claim_status' => $row['execution_claim_status'],
        'execution_claim_attempt' => $row['execution_claim_attempt'],
        'tool_description_fingerprint' => $row['tool_description_fingerprint'],
        'invocation_tool_description_fingerprint' => $row['invocation_tool_description_fingerprint'],
        'tool_description_matched' => $row['tool_description_matched'],
    ];

    // Mirroring the digest rule: the outcome is included ONLY for a review-stage record that HAS one. An
    // off-stage row, or a review row with a null outcome (historic), never folds it in — no re-identity.
    if (($row['stage'] ?? null) === 'review' && ($row['review_outcome'] ?? null) !== null) {
        $fields['review_outcome'] = $row['review_outcome'];
    }

    return $fields;
}

/** A review-stage record built directly with a FIXED recorded_at, so digest comparisons are deterministic. */
function reviewOutcomeRecord(?string $reviewOutcome, string $stage = 'review', bool $suppressFingerprint = false): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: 'envelope-review-1',
        capability: 'orders.cancel',
        stage: $stage,
        disposition: 'require_review',
        reason: 'An operator-facing message.',
        argumentFingerprint: str_repeat('a', 64),
        idempotencyKey: 'idem-review',
        approvalReceiptFingerprint: null,
        reviewRequestFingerprint: $suppressFingerprint || $reviewOutcome === 'not_admitted' || $reviewOutcome === null ? null : REVIEW_OUTCOME_FINGERPRINT,
        approvalPhase: null,
        approvalOutcome: null,
        targetPolicy: 'orders-target',
        targetStrategy: 'accept_stale_snapshot',
        proposalTargetIdentityFingerprint: str_repeat('c', 64),
        executionTargetIdentityFingerprint: str_repeat('d', 64),
        targetIdentityMatched: true,
        rateLimitKeyFingerprint: null,
        rateLimitPolicy: null,
        rateLimitLimit: null,
        rateLimitRemaining: null,
        rateLimitResetAt: null,
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        executionClaimPolicy: null,
        executionClaimStatus: null,
        executionClaimAttempt: null,
        recordedAt: new DateTimeImmutable('2026-08-31T09:30:00+00:00'),
        invocationId: 'invocation-review',
        toolKind: 'bound',
        configurationFingerprint: str_repeat('2', 64),
        actorFingerprint: str_repeat('3', 64),
        subjectFingerprint: str_repeat('4', 64),
        targetSource: 'proposal',
        reviewOutcome: $reviewOutcome,
    );
}

/** A review-stage evaluation whose durable outcome is DERIVED from its decision metadata (the real path). */
function reviewOutcomeEvaluation(string $outcome, string $fingerprint = REVIEW_OUTCOME_FINGERPRINT): Evaluation
{
    $metadata = match ($outcome) {
        'not_admitted' => [], // a pending refusal carries no request fingerprint
        'issued' => ['review_request_id' => 'rev_secret0123456789', 'review_request_fingerprint' => $fingerprint],
        'admitted' => ['review_request_id' => 'rev_secret0123456789', 'review_request_fingerprint' => $fingerprint, 'review_admitted' => true],
        default => throw new InvalidArgumentException("Unknown outcome {$outcome}."),
    };

    $capability = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('review-outcome-target'));
    $envelope = ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', ['order_id' => 7001], 'tool-call-1'),
        new ActionContext(actor: 'customer:72'),
    );

    return new Evaluation($envelope, $capability, ['order_id' => 7001], Decision::requireReview('Review.', $metadata), EvaluationStage::Review);
}

// ── the outcome is DERIVED from decision metadata and is a durable stable field ───────────────────

it('derives the durable review outcome from decision metadata and classifies the claim from it', function (): void {
    // fromEvaluation projects a durable outcome from the transient decision metadata: the admitted marker,
    // then a fingerprint (issuance), else a pending refusal.
    foreach (['issued' => ClaimType::ReviewRequestIssued, 'admitted' => ClaimType::ReviewRequestAdmitted, 'not_admitted' => ClaimType::ReviewNotAdmitted] as $outcome => $claim) {
        $evidence = DecisionEvidence::fromEvaluation(reviewOutcomeEvaluation($outcome));

        expect($evidence->reviewOutcome)->toBe($outcome) // a PUBLIC durable field, the recorder reads to persist
            ->and(RecordDigest::stableFields($evidence))->toHaveKey('review_outcome', $outcome)
            ->and($evidence->claimType)->toBe($claim);
    }
});

it('changes the record digest when only the outcome differs, at a fixed instant', function (): void {
    // Same fixed recorded_at and identical everything else — only the outcome differs. The real recordDigest
    // (CanonicalJson + scheme tag) must differ, so an issuance and an admission of one request are distinct.
    expect(reviewOutcomeRecord('issued')->recordDigest)
        ->not->toBe(reviewOutcomeRecord('admitted')->recordDigest)
        ->and(reviewOutcomeRecord('issued')->recordDigest)->toStartWith(RecordDigest::SCHEME.':');
});

it('leaves a non-review record’s stable fields untouched — no re-identity, no scheme bump', function (): void {
    // A non-review record has a null outcome; the key must be ABSENT from its stable fields, and its digest
    // must equal the exact value computed before review_outcome existed — byte-for-byte, no re-identity.
    $nonReview = reviewOutcomeRecord(null, stage: 'execution');

    expect(RecordDigest::stableFields($nonReview))->not->toHaveKey('review_outcome')
        ->and($nonReview->recordDigest)->toBe(NON_REVIEW_RECORD_DIGEST);
});

it('does not include or re-identify a review-stage record whose outcome is null', function (): void {
    // The rule is stage === Review AND a non-null outcome. A review record with a null outcome (a historic row
    // predating the field) must omit the key, keep its exact pre-field digest, and re-derive from the row the
    // same way — the durable column is nullable and the offline reader omits it.
    $reviewNull = reviewOutcomeRecord(null, stage: 'review');

    expect($reviewNull->reviewOutcome)->toBeNull()
        ->and(RecordDigest::stableFields($reviewNull))->not->toHaveKey('review_outcome')
        ->and($reviewNull->recordDigest)->toBe(REVIEW_NULL_RECORD_DIGEST);

    EvidenceTableSchema::createComplete();
    (new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection()))->record($reviewNull);
    $row = (array) app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->where('record_type', 'decision')->first();
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('evidence'));

    $fromRow = reviewStableFieldsFromRow($row);

    expect($row['review_outcome'])->toBeNull() // the column persists as null…
        ->and($fromRow)->not->toHaveKey('review_outcome') // …and the offline reader omits it…
        ->and(RecordDigest::SCHEME.':'.hash('sha256', CanonicalJson::encode($fromRow, 'record digest')))
        ->toBe($row['record_digest']); // …reproducing the stored digest
});

it('does not derive a review outcome for a NON-review evaluation carrying review-shaped metadata', function (): void {
    // The derivation must gate on stage === Review, NOT on metadata presence. An execution-stage record that
    // happens to carry review_admitted / review_request_fingerprint metadata (a downstream claim, say) must
    // stay reviewOutcome === null with the key absent from its stable fields — otherwise fromEvaluation could
    // re-identify ordinary execution evidence. (The exact byte-identical digest is pinned by the direct-
    // construction golden test above; here what matters is that the derivation itself never fires off-stage.)
    $capability = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('review-outcome-target'));
    $evaluation = new Evaluation(
        ActionEnvelope::wrap(new ActionProposal('orders.cancel', ['order_id' => 7001], 'tool-call-1'), new ActionContext(actor: 'customer:72')),
        $capability,
        ['order_id' => 7001],
        Decision::permit('Permitted outright.', ['review_admitted' => true, 'review_request_fingerprint' => REVIEW_OUTCOME_FINGERPRINT]),
        EvaluationStage::Execution,
    );
    $evidence = DecisionEvidence::fromEvaluation($evaluation);

    expect($evidence->reviewOutcome)->toBeNull()
        ->and(RecordDigest::stableFields($evidence))->not->toHaveKey('review_outcome');
});

it('appends reviewOutcome after the trailing intentId parameter, so positional construction stays compatible', function (): void {
    // reviewOutcome is a NEW optional constructor param. Inserting it before the pre-existing trailing intentId
    // would silently rebind a legacy positional caller's intent id as a review outcome. It must be appended
    // last — after intentId — so every existing positional argument keeps its meaning and reviewOutcome
    // defaults to null.
    $params = (new ReflectionMethod(DecisionEvidence::class, '__construct'))->getParameters();
    $names = array_map(fn (ReflectionParameter $p): string => $p->getName(), $params);
    $intentIndex = array_search('intentId', $names, true);
    $reviewIndex = array_search('reviewOutcome', $names, true);

    expect($intentIndex)->toBeInt()
        ->and($reviewIndex)->toBeInt()
        ->and($reviewIndex)->toBeGreaterThan($intentIndex) // appended AFTER the old final intentId
        ->and($names[array_key_last($names)])->toBe('reviewOutcome') // it is the new last parameter
        // …and it is optional with a null default, so a legacy positional caller that omits it gets null.
        ->and($params[$reviewIndex]->isDefaultValueAvailable())->toBeTrue()
        ->and($params[$reviewIndex]->getDefaultValue())->toBeNull();
});

it('cannot alter a non-review record’s digest by supplying an off-stage reviewOutcome to the constructor', function (): void {
    // reviewOutcome is a public constructor field, so an off-stage non-null value is constructible directly.
    // The digest rule must gate inclusion on stage === Review, NOT on non-null — otherwise a directly-built
    // execution record could be re-identified. Two execution records identical but for a supplied outcome must
    // share the exact pre-change digest, with the key omitted from both.
    $supplied = reviewOutcomeRecord('admitted', stage: 'execution', suppressFingerprint: true);
    $absent = reviewOutcomeRecord(null, stage: 'execution');

    expect(RecordDigest::stableFields($supplied))->not->toHaveKey('review_outcome')
        ->and($supplied->recordDigest)->toBe($absent->recordDigest)
        ->and($supplied->recordDigest)->toBe(NON_REVIEW_RECORD_DIGEST);
});

// ── the outcome survives the durable round-trip; a reader re-derives the claim from the row alone ──

it('persists each outcome as its column, re-derives the claim and digest from the row, and keeps no raw id', function (string $outcome, ClaimType $claim, ?string $fingerprint): void {
    EvidenceTableSchema::createComplete();

    $evidence = DecisionEvidence::fromEvaluation(reviewOutcomeEvaluation($outcome));
    (new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection()))->record($evidence);
    $row = (array) app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->where('record_type', 'decision')->first();

    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('evidence'));

    // A reader holding only the row re-derives BOTH the claim type and the digest from the durable columns —
    // recomputing the canonical digest from the row (conditional review_outcome included) reproduces the
    // stored value, so an admission is never mistaken for a pending issuance offline, nor vice versa.
    $fromRow = reviewStableFieldsFromRow($row);

    expect($row['review_outcome'])->toBe($outcome)
        ->and(ClaimType::for($row['stage'], $row['disposition'], $row['review_outcome']))->toBe($claim)
        ->and($fromRow)->toBe(RecordDigest::stableFields($evidence)) // the row reproduces the stable-field map…
        ->and(RecordDigest::SCHEME.':'.hash('sha256', CanonicalJson::encode($fromRow, 'record digest')))
        ->toBe($row['record_digest']) // …and the digest recomputes from the row alone
        ->and($row['record_digest'])->toBe($evidence->recordDigest)
        // fingerprint-only privacy: a pending refusal has no fingerprint; issuance/admission keep the
        // fingerprint but never the raw id.
        ->and($row['review_request_fingerprint'])->toBe($fingerprint)
        ->and($row)->not->toHaveKey('review_request_id')
        ->and(implode('|', array_map(fn ($v): string => (string) $v, $row)))->not->toContain('rev_');
})->with([
    'issued' => ['issued', ClaimType::ReviewRequestIssued, REVIEW_OUTCOME_FINGERPRINT],
    'admitted' => ['admitted', ClaimType::ReviewRequestAdmitted, REVIEW_OUTCOME_FINGERPRINT],
    'not_admitted' => ['not_admitted', ClaimType::ReviewNotAdmitted, null],
]);

it('reproduces a NON-review row digest from the row too — the conditional inclusion holds both ways', function (): void {
    // Exercises the omit-when-null branch of offline re-derivation: a non-review row has no review_outcome, so
    // the reader omits the key and still reproduces the stored digest.
    EvidenceTableSchema::createComplete();

    $evidence = reviewOutcomeRecord(null, stage: 'execution');
    (new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection()))->record($evidence);
    $row = (array) app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->where('record_type', 'decision')->first();

    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('evidence'));

    $fromRow = reviewStableFieldsFromRow($row);

    expect($row['review_outcome'])->toBeNull()
        ->and($fromRow)->not->toHaveKey('review_outcome')
        ->and(RecordDigest::SCHEME.':'.hash('sha256', CanonicalJson::encode($fromRow, 'record digest')))
        ->toBe($row['record_digest']);
});

// ── the outcome travels in the attest payload the signature covers ────────────────────────────────

it('carries each review outcome into the attest chain payload, never the raw id', function (string $outcome): void {
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();
    $store = AttestFixture::store();
    $recorder = new AttestEvidenceRecorder(
        attest: AttestFixture::registry($store),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        onFailure: 'alert',
        baseDelayMs: 1,
    );

    $recorder->record(DecisionEvidence::fromEvaluation(reviewOutcomeEvaluation($outcome)));
    $payload = $store->tail('verdict')->envelope->payload;

    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('evidence'));
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('derivations'));

    expect($payload['review_outcome'])->toBe($outcome)
        ->and($payload)->not->toHaveKey('review_request_id') // the key is absent, not merely one raw value
        ->and(json_encode($payload))->not->toContain('rev_secret0123456789');
})->with(['issued', 'admitted', 'not_admitted']);

it('carries an off-stage reviewOutcome into the attest payload too — the durable sinks are stage-independent', function (): void {
    // The DIGEST omits an off-stage outcome (stage-gated), but the durable SINKS persist the property as-is,
    // symmetric with the DB column write. An Attest-only deployment must not silently lose the field.
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();
    $store = AttestFixture::store();
    $recorder = new AttestEvidenceRecorder(
        attest: AttestFixture::registry($store),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        onFailure: 'alert',
        baseDelayMs: 1,
    );

    $recorder->record(reviewOutcomeRecord('admitted', stage: 'execution', suppressFingerprint: true));
    $payload = $store->tail('verdict')->envelope->payload;

    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('evidence'));
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('derivations'));

    expect($payload['review_outcome'])->toBe('admitted');
});
