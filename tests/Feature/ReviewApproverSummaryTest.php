<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\ApproverSummaryMaterializer;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalOperation;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewManager;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Support\ApproverSummary;
use Fissible\Verdict\Testing\AllowAllReviewAuthorizer;
use Fissible\Verdict\Tests\Support\FrozenClock;
use Fissible\Verdict\VerdictManager;
use Illuminate\Database\DatabaseManager;

// ADR 0038 §1/§6 — the review lane produces the approver summary at ISSUANCE through the SAME materialisation
// service the confirmation lane uses (ApproverSummaryMaterializer): the app-authored, binding-informed candidate
// is routed through the approver-audience release policy, and a Released summary is persisted on the ReviewRequest.
// The binding is resolved ONCE and feeds both the binding fingerprint and the summary. Absence (no describer, or a
// candidate the policy withholds) leaves approverSummary null. This completes confirmation/review parity for #306;
// slice 5's review operational event already reads request->approverSummary?->fingerprint, so it now lights up.

function reviewSummaryManager(
    ReviewRequestStore $store,
    bool $withMaterializer = true,
    ?EvidenceWriter $evidence = null,
): ReviewManager {
    return new ReviewManager(
        reviews: $store,
        clock: new FrozenClock('2026-08-31 12:00:00'),
        authorizer: new AllowAllReviewAuthorizer,
        defaultTtlSeconds: 900,
        evidence: $evidence,
        invocations: app(InvocationContext::class),
        events: null,
        summaries: $withMaterializer ? new ApproverSummaryMaterializer(app(ContextReleaseManager::class)) : null,
    );
}

function reviewSummaryCapability(bool $withDescriber = true, ?int &$bindCalls = null, ?array &$sawBinding = null, bool $invalidDescriber = false, ?string $describerText = null): Capability
{
    $capability = Capability::usingPolicy('orders.cancel', 'update', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('review-summary-target'))
        ->requiresConfirmation(
            bindUsing: function (ActionEnvelope $envelope, array $target) use (&$bindCalls): array {
                $bindCalls = ($bindCalls ?? 0) + 1;

                return ['bound_order' => $target['order_id']];
            },
            reason: 'Confirm this cancellation.',
        );

    if ($withDescriber) {
        $capability = $capability->describeForApprover(
            function (ActionEnvelope $envelope, mixed $target, array $binding) use (&$sawBinding, $invalidDescriber, $describerText): string {
                $sawBinding = $binding;

                // A control character violates the display contract → NotReleased (never persisted as a summary).
                return $invalidDescriber
                    ? "Cancel order\x00#{$binding['bound_order']}"
                    : ($describerText ?? "Cancel order #{$binding['bound_order']}");
            },
        );
    }

    return $capability;
}

function reviewSummaryEvaluation(Capability $capability, int $orderId = 7001): Evaluation
{
    $envelope = ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', ['order_id' => $orderId], 'tool-call-1'),
        new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']),
    );

    return new Evaluation($envelope, $capability, ['order_id' => $orderId], Decision::requireReview('A human must review.'), EvaluationStage::Proposal);
}

/** Register the approver-audience policy so the summary materialises as Released. */
function reviewPermitSummaries(): void
{
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Untrusted, Trust::Trusted),
    );
}

/** The binding fingerprint the confirmation-lane form produces for this evaluation. */
function expectedReviewSummaryFingerprint(int $orderId): string
{
    return ArgumentFingerprint::make([
        'capability' => 'orders.cancel',
        'execution_target_policy' => 'review-summary-target',
        'arguments' => ['order_id' => $orderId],
        'binding' => ['bound_order' => $orderId],
        'approval_context' => ['tenant_id' => 'store-1'],
    ]);
}

// ── a Released summary is materialised at issuance and persisted on the request ────────────────────────

it('materialises a Released approver summary onto the review request at issuance', function (): void {
    reviewPermitSummaries();
    $store = new InMemoryReviewRequestStore;

    $transition = reviewSummaryManager($store)->issue(reviewSummaryEvaluation(reviewSummaryCapability(), 7001));
    $request = $transition->request;

    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($request->approverSummary)->toBeInstanceOf(ApproverSummary::class)
        ->and($request->approverSummary->content)->toBe('Cancel order #7001')
        ->and($request->approverSummary->fingerprint)->toBe(hash('sha256', 'Cancel order #7001'));
});

it('leaves the summary null when the capability registers no describer', function (): void {
    reviewPermitSummaries();
    $store = new InMemoryReviewRequestStore;

    $transition = reviewSummaryManager($store)->issue(reviewSummaryEvaluation(reviewSummaryCapability(withDescriber: false)));

    expect($transition->request->approverSummary)->toBeNull();
});

it('leaves the summary null when no approver-audience policy is registered', function (): void {
    // A describer authored a candidate, but no policy exists for the approver-audience route → ReleaseDenied → no
    // summary is retained on the request (ADR 0038 §3).
    $store = new InMemoryReviewRequestStore;

    $transition = reviewSummaryManager($store)->issue(reviewSummaryEvaluation(reviewSummaryCapability()));

    expect($transition->request->approverSummary)->toBeNull();
});

it('leaves the summary null when a registered policy withholds it', function (): void {
    // A policy exists for the route but only for the TRUSTED class; the summary is classified UNTRUSTED, so a
    // valid candidate is withheld — ReleaseDenied, distinct from "no policy".
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Trusted),
    );
    $store = new InMemoryReviewRequestStore;

    $transition = reviewSummaryManager($store)->issue(reviewSummaryEvaluation(reviewSummaryCapability()));

    expect($transition->request->approverSummary)->toBeNull();
});

it('leaves the summary null for a display-contract-invalid candidate, and issuance still succeeds', function (): void {
    // Normal mode never fails issuance on an invalid summary (ADR 0038 §5): the request is minted with no summary.
    // And the invalid candidate is rejected at the value boundary BEFORE the release route — nothing is routed.
    reviewPermitSummaries();
    $store = new InMemoryReviewRequestStore;
    $recorder = app(EvidenceRecorder::class);
    $releasesBefore = $recorder instanceof InMemoryEvidenceRecorder ? count($recorder->releases()) : 0;

    $transition = reviewSummaryManager($store)->issue(reviewSummaryEvaluation(reviewSummaryCapability(invalidDescriber: true)));

    $releasesAfter = $recorder instanceof InMemoryEvidenceRecorder ? count($recorder->releases()) : 0;
    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($transition->request->approverSummary)->toBeNull()
        ->and($releasesAfter)->toBe($releasesBefore); // the release route was never consulted
});

it('leaves the summary null when the manager has no materialiser (optional dependency)', function (): void {
    reviewPermitSummaries();
    $store = new InMemoryReviewRequestStore;

    $transition = reviewSummaryManager($store, withMaterializer: false)->issue(reviewSummaryEvaluation(reviewSummaryCapability()));

    expect($transition->request->approverSummary)->toBeNull();
});

// ── the binding is resolved once and shared between the fingerprint and the summary ────────────────────

it('resolves the binding exactly once during issuance, feeding both the fingerprint and the summary', function (): void {
    reviewPermitSummaries();
    $store = new InMemoryReviewRequestStore;
    $bindCalls = 0;
    $sawBinding = null;

    $transition = reviewSummaryManager($store)->issue(reviewSummaryEvaluation(reviewSummaryCapability(bindCalls: $bindCalls, sawBinding: $sawBinding), 7001));

    expect($bindCalls)->toBe(1) // NOT once for the fingerprint and again for the summary
        ->and($sawBinding)->toBe(['bound_order' => 7001]) // the describer was handed the already-computed binding
        // …and sharing the binding did not change the fingerprint the gate matches on.
        ->and($transition->request->bindingFingerprint)->toBe(expectedReviewSummaryFingerprint(7001));
});

// ── the summary survives the durable review-store round-trip ───────────────────────────────────────────

it('persists the review approver summary through the database store', function (): void {
    reviewPermitSummaries();
    $connection = app(DatabaseManager::class)->connection();
    $table = (string) config('verdict.reviews.table', 'verdict_review_requests');
    $connection->getSchemaBuilder()->dropIfExists($table);
    (require __DIR__.'/../../database/migrations/create_verdict_review_requests_table.php.stub')->up();

    $store = new DatabaseReviewRequestStore($connection);
    $issued = reviewSummaryManager($store)->issue(reviewSummaryEvaluation(reviewSummaryCapability(), 4242));
    $hydrated = $store->find($issued->request->id);
    $rawColumn = (string) $connection->table($table)->where('id', $issued->request->id)->value('approver_summary');

    $connection->getSchemaBuilder()->dropIfExists($table);

    expect($hydrated?->approverSummary?->content)->toBe('Cancel order #4242')
        ->and($hydrated?->approverSummary?->fingerprint)->toBe(hash('sha256', 'Cancel order #4242'))
        // The summary really landed in the durable column, not just on the returned object.
        ->and($rawColumn)->toContain(hash('sha256', 'Cancel order #4242'));
});

// ── integration with slice 5: the review operational event now carries the summary fingerprint ─────────

it('carries the released summary fingerprint on the review issuance operational event', function (): void {
    reviewPermitSummaries();
    $store = new InMemoryReviewRequestStore;
    $recorder = new InMemoryEvidenceRecorder;

    $transition = reviewSummaryManager($store, evidence: $recorder)->issue(reviewSummaryEvaluation(reviewSummaryCapability(), 7001));

    expect($recorder->operations())->toHaveCount(1);
    $event = $recorder->operations()[0];
    expect($event->operation)->toBe(ApprovalOperation::Issued)
        ->and($event->lane)->toBe(ApprovalLane::Review)
        ->and($event->summaryFingerprint)->not->toBeNull()
        ->and($event->summaryFingerprint)->toBe($transition->request->approverSummary?->fingerprint);
});

// ── idempotence, the non-review disposition, and routing through the shared materialiser ───────────────

it('does not overwrite or re-emit on an idempotent re-issue — the original summary is preserved', function (): void {
    reviewPermitSummaries();
    $store = new InMemoryReviewRequestStore;
    $recorder = new InMemoryEvidenceRecorder;
    $manager = reviewSummaryManager($store, evidence: $recorder);

    $first = $manager->issue(reviewSummaryEvaluation(reviewSummaryCapability(describerText: 'ORIGINAL summary'), 7001));
    // Same binding (order 7001) so the store treats it as a re-issue, but a DIFFERENT describer output — a broken
    // implementation that overwrote the stored summary would surface 'OVERWRITE attempt' here.
    $second = $manager->issue(reviewSummaryEvaluation(reviewSummaryCapability(describerText: 'OVERWRITE attempt'), 7001));

    expect($second->outcome)->toBe(ReviewOutcome::Existing)
        ->and($second->request->approverSummary?->content)->toBe('ORIGINAL summary') // the durable original wins
        ->and($second->request->approverSummary?->fingerprint)->toBe($first->request->approverSummary?->fingerprint)
        ->and($recorder->operations())->toHaveCount(1); // Existing is not a fresh Issued event
});

it('does not resolve the binding, describe, or materialise for a non-RequireReview disposition', function (): void {
    reviewPermitSummaries();
    $store = new InMemoryReviewRequestStore;
    $bindCalls = 0;
    $sawBinding = null;
    $capability = reviewSummaryCapability(bindCalls: $bindCalls, sawBinding: $sawBinding);
    // A confirmation-disposition evaluation must be refused by issue() before any summary work happens.
    $envelope = ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', ['order_id' => 7001], 'tool-call-1'),
        new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']),
    );
    $evaluation = new Evaluation($envelope, $capability, ['order_id' => 7001], Decision::requireConfirmation('Confirm.'), EvaluationStage::Proposal);

    $transition = reviewSummaryManager($store)->issue($evaluation);

    expect($transition->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($bindCalls)->toBe(0)   // no binding resolved
        ->and($sawBinding)->toBeNull(); // no describer invoked
});

it('routes a permitted summary through ContextReleaseManager with the approver-audience classification', function (): void {
    // Proves the shared materialiser's ADR 0008 route was used, not bespoke summary creation.
    reviewPermitSummaries();
    reviewSummaryManager(new InMemoryReviewRequestStore)->issue(reviewSummaryEvaluation(reviewSummaryCapability(), 7001));

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);
    $permit = $recorder->releases()[array_key_last($recorder->releases())];
    expect($permit->source)->toBe(ApproverAudience::source()->identity())
        ->and($permit->destination)->toBe(ApproverAudience::destination()->identity())
        ->and($permit->trust)->toBe(Trust::Untrusted)
        ->and($permit->dataClass)->toBe(DataClass::Internal)
        ->and($permit->disposition)->toBe('permit');
});

it('records a deny release evidence when the review summary is withheld by policy', function (): void {
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Trusted), // does not cover the Untrusted summary
    );
    reviewSummaryManager(new InMemoryReviewRequestStore)->issue(reviewSummaryEvaluation(reviewSummaryCapability(), 7001));

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);
    $deny = $recorder->releases()[array_key_last($recorder->releases())];
    expect($deny->disposition)->toBe('deny')
        ->and($deny->trust)->toBe(Trust::Untrusted)
        ->and($deny->dataClass)->toBe(DataClass::Internal);
});
