<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Exceptions\RequireReviewNotImplemented;
use Fissible\Verdict\Exceptions\ReviewRequestNotIssued;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

// ADR 0035 §7 — run() is the unbound entry point: it can never ADMIT a reviewed execution, so a
// RequireReview decision ISSUES a durable ReviewRequest and REFUSES with a structured review-pending result.
// This retires #429's loud-reserve throw for run() when a review lane is configured, and preserves the throw
// when it is not. The durable audit trail is fingerprint-only: the result's decision metadata carries the raw
// review_request_id for the immediate caller, but the DecisionEvidence records a review-request FINGERPRINT
// (the request's bindingFingerprint), mirroring approvalReceiptFingerprint — the refusal and the issued
// request correlate without retaining a raw identifier durably. runBound()'s issuance-at-execution and the
// admission of an approved review (§5) are the next slice (6b); here run() always refuses.

const REVIEW_PENDING_REASON = 'This action requires human review; a review request is pending a decision.';

function refuseEnvelope(string $toolCallId = 'tool-call-1', int $orderId = 7001, string $tenant = 'store-1'): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal('orders.cancel', ['order_id' => $orderId], $toolCallId),
        context: new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => $tenant]),
    );
}

function refuseCapability(?bool &$executed = null, bool $withBinder = true): Capability
{
    $capability = Capability::usingPolicy(
        name: 'orders.cancel',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): array => $envelope->proposal->arguments,
    )->executionTarget(acceptTestSnapshot('refuse-target'))
        ->executeUsing(function (AuthorizedAction $action) use (&$executed): string {
            $executed = true;

            return 'cancelled';
        });

    if ($withBinder) {
        $capability = $capability->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $envelope, array $target): array => ['bound_order' => $target['order_id']],
            reason: 'Confirm this cancellation.',
        );
    }

    return $capability;
}

function bindDecisionAuthorizer(callable $decide): void
{
    app()->instance(CapabilityAuthorizer::class, new class($decide) implements CapabilityAuthorizer
    {
        public function __construct(private $decide) {}

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return ($this->decide)($capability, $envelope, $target);
        }
    });
}

function bindRequireReviewAuthorizer(): void
{
    bindDecisionAuthorizer(fn (): Decision => Decision::requireReview('A human must review this cancellation.'));
}

function enableReviewLane(): void
{
    // A configured review lane depends on a durable STORE only — issuance never consults the decision
    // authorizer (that governs later approve/reject). Bind a shared in-memory store so the test can inspect
    // what issuance wrote; the production default store lands with the database slice.
    app()->instance(ReviewRequestStore::class, new InMemoryReviewRequestStore);
    config()->set('verdict.reviews.store', InMemoryReviewRequestStore::class);
}

function issuedReviewStore(): InMemoryReviewRequestStore
{
    $store = app(ReviewRequestStore::class);
    assert($store instanceof InMemoryReviewRequestStore);

    return $store;
}

/** @return list<DecisionEvidence> */
function reviewStageEvidence(string $stage): array
{
    $recorder = app(EvidenceWriter::class);
    assert($recorder instanceof InMemoryEvidenceRecorder);

    return array_values(array_filter($recorder->all(), fn (DecisionEvidence $e): bool => $e->stage === $stage));
}

function reviewEvidenceEvaluation(string $fingerprint): Evaluation
{
    return new Evaluation(
        refuseEnvelope(),
        refuseCapability(),
        ['order_id' => 7001],
        // The decision metadata carries BOTH the raw id (for the immediate caller) and the fingerprint;
        // the durable evidence must project only the fingerprint, so 'rev_secret' must not reach the row.
        Decision::requireReview(REVIEW_PENDING_REASON, [
            'review_request_id' => 'rev_secret0123456789',
            'review_request_fingerprint' => $fingerprint,
        ]),
        EvaluationStage::Review,
    );
}

function runReview(VerdictManager $verdict, ActionEnvelope $envelope, ?bool &$ran = null): mixed
{
    return $verdict->run($envelope, function () use (&$ran): string {
        $ran = true;

        return 'done';
    });
}

// ── run() issues and refuses when a review lane is configured ─────────────────────────────────────

it('run() issues a review request and refuses with a review-pending result, not a throw', function (): void {
    enableReviewLane();
    bindRequireReviewAuthorizer();
    $verdict = app(VerdictManager::class);
    $verdict->capability(refuseCapability());

    $ran = false;
    $result = runReview($verdict, refuseEnvelope(), $ran);

    $stored = issuedReviewStore()->all();
    $requestId = $result->evaluation->decision->metadata['review_request_id'] ?? null;

    expect($result->executed)->toBeFalse()
        ->and($ran)->toBeFalse()
        ->and($result->evaluation->decision->disposition)->toBe(Disposition::RequireReview)
        ->and($result->evaluation->decision->reason)->toBe(REVIEW_PENDING_REASON) // a defined pending contract, not the policy reason
        ->and($requestId)->not->toBeNull()
        ->and($stored)->toHaveCount(1)
        ->and($stored[$requestId]->status)->toBe(ReviewStatus::Pending);
});

it('records the refusal durably as a review-request FINGERPRINT, never the raw id', function (): void {
    enableReviewLane();
    bindRequireReviewAuthorizer();
    $verdict = app(VerdictManager::class);
    $verdict->capability(refuseCapability());

    $result = runReview($verdict, refuseEnvelope());
    $request = array_values(issuedReviewStore()->all())[0];

    // The proposal decision is recorded before issuance…
    expect(reviewStageEvidence('proposal'))->toHaveCount(1)
        ->and(reviewStageEvidence('proposal')[0]->disposition)->toBe('require_review');

    // …and a review-stage record correlates to the issued request by its binding fingerprint, fingerprint-only.
    $review = reviewStageEvidence('review');
    expect($review)->toHaveCount(1)
        ->and($review[0]->reviewRequestFingerprint)->toBe($request->bindingFingerprint)
        // The durable evidence carries no raw review-request id anywhere on the record.
        ->and(json_encode($review[0]))->not->toContain($result->evaluation->decision->metadata['review_request_id']);
});

it('persists the review-request fingerprint end to end — column, digest, and no raw id in the row', function (): void {
    // The durable contract, not just the in-memory object: the DatabaseEvidenceRecorder writes the
    // review_request_fingerprint column, RecordDigest covers it, and the persisted row retains no raw id.
    EvidenceTableSchema::createComplete(); // the real schema fixture, which must carry the new column

    $fingerprint = str_repeat('a', 64);
    $evidence = DecisionEvidence::fromEvaluation(reviewEvidenceEvaluation($fingerprint));

    (new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection()))->record($evidence);
    $row = (array) app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->first();

    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('evidence'));

    expect($row['review_request_fingerprint'])->toBe($fingerprint)
        ->and($row)->not->toHaveKey('review_request_id')
        ->and(implode('|', array_map(fn ($v): string => (string) $v, $row)))->not->toContain('rev_'); // no raw id value
});

it('carries the review-request fingerprint into the attest chain payload, never the raw id', function (): void {
    // Attest is a second durable projection that serializes its OWN payload — the fingerprint-only privacy
    // contract must hold there too: the signed payload carries review_request_fingerprint and no raw id.
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

    $recorder->record(DecisionEvidence::fromEvaluation(reviewEvidenceEvaluation(str_repeat('a', 64))));
    $payload = $store->tail('verdict')->envelope->payload;

    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('evidence'));
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('derivations'));

    expect($payload['review_request_fingerprint'])->toBe(str_repeat('a', 64))
        ->and(json_encode($payload))->not->toContain('rev_secret0123456789');
});

it('includes the review-request fingerprint in the record digest', function (): void {
    $a = DecisionEvidence::fromEvaluation(reviewEvidenceEvaluation(str_repeat('a', 64)));
    $b = DecisionEvidence::fromEvaluation(reviewEvidenceEvaluation(str_repeat('b', 64)));

    // Two records identical but for the fingerprint must not share a content digest.
    expect($a->recordDigest)->not->toBe($b->recordDigest);
});

it('lazily resolves the review lane — configured AFTER the manager is built', function (): void {
    // The manager captures the review lane through a closure, so configuring the store after resolution works;
    // an implementation that eagerly captured a null lane at construction would still throw here.
    bindRequireReviewAuthorizer();          // the authorizer is captured eagerly, so bind it BEFORE resolving
    $verdict = app(VerdictManager::class);
    enableReviewLane();                      // the review STORE/lane is the lazily-resolved part, set after
    $verdict->capability(refuseCapability());

    $result = runReview($verdict, refuseEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($result->evaluation->decision->metadata['review_request_id'] ?? null)->not->toBeNull()
        ->and(issuedReviewStore()->all())->toHaveCount(1);
});

it('is idempotent — re-proposing the same binding returns the existing request', function (): void {
    enableReviewLane();
    bindRequireReviewAuthorizer();
    $verdict = app(VerdictManager::class);
    $verdict->capability(refuseCapability());

    $first = runReview($verdict, refuseEnvelope('call-1'));
    $second = runReview($verdict, refuseEnvelope('call-2')); // same capability/arguments/context binding

    expect($first->evaluation->decision->metadata['review_request_id'])
        ->toBe($second->evaluation->decision->metadata['review_request_id'])
        ->and(issuedReviewStore()->all())->toHaveCount(1);
});

it('issues a distinct request for a different bound action', function (): void {
    enableReviewLane();
    bindRequireReviewAuthorizer();
    $verdict = app(VerdictManager::class);
    $verdict->capability(refuseCapability());

    runReview($verdict, refuseEnvelope('call-1', orderId: 7001));
    runReview($verdict, refuseEnvelope('call-2', orderId: 9009)); // different arguments → different binding

    expect(issuedReviewStore()->all())->toHaveCount(2);
});

it('surfaces a distinct exception when a review-required capability cannot be bound to issue against', function (): void {
    enableReviewLane();
    bindRequireReviewAuthorizer();
    $verdict = app(VerdictManager::class);
    $verdict->capability(refuseCapability(withBinder: false)); // no application binding → issuance InvalidState

    // A configured lane that cannot open the review must fail loudly with a DISTINCT signal from the
    // unconfigured loud-reserve — the misconfiguration names the capability and persists nothing.
    expect(fn (): mixed => runReview($verdict, refuseEnvelope()))
        ->toThrow(ReviewRequestNotIssued::class, 'orders.cancel');
    expect(issuedReviewStore()->all())->toBe([]);
});

// ── the loud-reserve is preserved when no review lane is configured ───────────────────────────────

it('run() still throws the loud reserve when no review lane is configured', function (): void {
    bindRequireReviewAuthorizer();
    $verdict = app(VerdictManager::class);
    $verdict->capability(refuseCapability());

    expect(fn (): mixed => runReview($verdict, refuseEnvelope()))
        ->toThrow(RequireReviewNotImplemented::class);
});

it('runBound() still throws the loud reserve when NO review lane is configured', function (): void {
    // The loud reserve is preserved only while UNCONFIGURED. With a lane configured, runBound() now
    // issues-at-execution and admits an approved review (6b, ReviewAdmissionGateTest); it no longer throws.
    bindRequireReviewAuthorizer();
    $verdict = app(VerdictManager::class);
    $verdict->capability(refuseCapability());

    expect(fn (): mixed => $verdict->runBound(refuseEnvelope()))
        ->toThrow(RequireReviewNotImplemented::class);
});

// ── other dispositions are untouched by the run() issue-refuse path ───────────────────────────────

it('still executes a permitted run() action with a review lane configured, issuing no review', function (): void {
    enableReviewLane();
    bindDecisionAuthorizer(fn (): Decision => Decision::permit());
    $verdict = app(VerdictManager::class);
    $verdict->capability(refuseCapability(withBinder: false));

    $ran = false;
    $result = runReview($verdict, refuseEnvelope(), $ran);

    expect($result->executed)->toBeTrue()
        ->and($ran)->toBeTrue()
        ->and(issuedReviewStore()->all())->toBe([]);
});

it('leaves a plain Deny as a returned denial with a review lane configured, issuing no review', function (): void {
    enableReviewLane();
    bindDecisionAuthorizer(fn (): Decision => Decision::deny('Not permitted.'));
    $verdict = app(VerdictManager::class);
    $verdict->capability(refuseCapability(withBinder: false));

    $result = runReview($verdict, refuseEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($result->evaluation->decision->disposition)->toBe(Disposition::Deny)
        ->and(issuedReviewStore()->all())->toBe([]);
});

it('leaves a RequireConfirmation run() as a returned denial, issuing no review', function (): void {
    // run() has no confirmation pause; a RequireConfirmation is simply non-permitting here. Either way, the
    // review issue-refuse path must not fire — only RequireReview issues a review.
    enableReviewLane();
    bindDecisionAuthorizer(fn (): Decision => Decision::requireConfirmation('Confirm this.'));
    $verdict = app(VerdictManager::class);
    $verdict->capability(refuseCapability(withBinder: false));

    $result = runReview($verdict, refuseEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($result->evaluation->decision->disposition)->toBe(Disposition::RequireConfirmation)
        ->and(issuedReviewStore()->all())->toBe([]);
});
