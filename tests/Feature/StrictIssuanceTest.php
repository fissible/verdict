<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\ApproverProvenanceRelease;
use Fissible\Verdict\Approvals\ApproverSummaryMaterializer;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\IssuanceRefusalReason;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\AttestsIssuance;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewManager;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Support\ApproverSummary;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\Verdict\Testing\AllowAllReviewAuthorizer;
use Fissible\Verdict\Tests\Support\FrozenClock;
use Fissible\Verdict\VerdictManager;

// ADR 0038 §5 (Strict tier) — a capability that requiresAttestedIssuance() gets a SYNCHRONOUS, issuance-BLOCKING
// attested step. At issuance the id is generated in memory, its fingerprint attested through the AttestsIssuance
// seam, and ONLY on success is the receipt/request persisted. If the summary was not Released, or no attest backend
// is configured, or the attest append fails, issuance is REFUSED — no receipt/request is minted — with a single
// IssuanceRefused outcome and a typed reason. Non-strict capabilities are never blocked by this path.

// ── test doubles ───────────────────────────────────────────────────────────────────────────────────────

final class RecordingAttestsIssuance implements AttestsIssuance
{
    /** @var list<array{lane: ApprovalLane, identityFingerprint: string, summaryFingerprint: string}> */
    public array $calls = [];

    /** @var list<int> */
    public array $storeSizeAtAttest = [];

    /** @param (Closure(): int)|null $probe */
    public function __construct(private ?Closure $probe = null) {}

    public function attestIssuedSummary(ApprovalLane $lane, string $identityFingerprint, ApproverSummary $summary): void
    {
        $this->calls[] = ['lane' => $lane, 'identityFingerprint' => $identityFingerprint, 'summaryFingerprint' => $summary->fingerprint];

        if ($this->probe !== null) {
            $this->storeSizeAtAttest[] = ($this->probe)();
        }
    }
}

final class ThrowingAttestsIssuance implements AttestsIssuance
{
    public int $calls = 0;

    public function attestIssuedSummary(ApprovalLane $lane, string $identityFingerprint, ApproverSummary $summary): void
    {
        $this->calls++;

        throw new RuntimeException('attest chain is unavailable');
    }
}

// ── confirmation-lane harness ────────────────────────────────────────────────────────────────────────

function strictConfirmationManager(InMemoryApprovalReceiptStore $store, ?AttestsIssuance $attest): ApprovalManager
{
    return new ApprovalManager(
        receipts: $store,
        executionContext: app(ApprovalExecutionContext::class),
        clock: app(Clock::class),
        approverProvenance: app(ApproverProvenanceRelease::class),
        invocations: app(InvocationContext::class),
        defaultTtlSeconds: 900,
        authorizer: new AllowAllApprovalAuthorizer,
        summaries: new ApproverSummaryMaterializer(app(ContextReleaseManager::class)),
        evidence: null,
        events: null,
        attestedIssuance: $attest,
    );
}

function strictConfirmationCapability(bool $strict = true, bool $withDescriber = true): Capability
{
    $capability = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('strict-target'))
        ->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $e, array $target): array => ['bound_order' => $target['order_id']],
            reason: 'Confirm this cancellation.',
        );

    if ($withDescriber) {
        $capability = $capability->describeForApprover(fn (ActionEnvelope $e, mixed $t, array $b): string => "Cancel order #{$b['bound_order']}");
    }

    return $strict ? $capability->requiresAttestedIssuance() : $capability;
}

function strictConfirmationEvaluation(Capability $capability, int $orderId = 9001): Evaluation
{
    $envelope = ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', ['order_id' => $orderId], 'tool-call-1'),
        new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']),
    );

    return new Evaluation($envelope, $capability, ['order_id' => $orderId], Decision::requireConfirmation('Confirm.'), EvaluationStage::Execution);
}

function strictPermitSummaries(): void
{
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Untrusted, Trust::Trusted),
    );
}

// ── confirmation lane: the happy strict path mints only after a successful attest ──────────────────────

it('mints a strict confirmation receipt only after attesting the released summary, before persisting', function (): void {
    strictPermitSummaries();
    $store = new InMemoryApprovalReceiptStore;
    // The probe captures how many receipts the store holds at the moment attestation runs.
    $attest = new RecordingAttestsIssuance(fn (): int => count($store->all()));

    $transition = strictConfirmationManager($store, $attest)->issue(strictConfirmationEvaluation(strictConfirmationCapability()));
    $receipt = $transition->receipt;

    expect($transition->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($receipt)->not->toBeNull()
        ->and($attest->calls)->toHaveCount(1)
        ->and($attest->calls[0]['lane'])->toBe(ApprovalLane::Confirmation)
        // the attested identity anchor is the SAME id that was then persisted
        ->and($attest->calls[0]['identityFingerprint'])->toBe(hash('sha256', $receipt->id))
        ->and($attest->calls[0]['summaryFingerprint'])->toBe($receipt->approverSummary?->fingerprint)
        // attestation happened BEFORE the receipt was persisted (store was empty at attest time)
        ->and($attest->storeSizeAtAttest)->toBe([0])
        ->and($store->all())->toHaveCount(1)
        // the persisted receipt is the SAME id that was attested — not a different one minted alongside
        ->and(array_values($store->all())[0]->id)->toBe($receipt->id);
});

// ── confirmation lane: every fail-closed refusal (no mint, one outcome, typed reason) ──────────────────

it('refuses a strict issuance when the attest append fails, minting nothing', function (): void {
    strictPermitSummaries();
    $store = new InMemoryApprovalReceiptStore;
    $attest = new ThrowingAttestsIssuance;

    $transition = strictConfirmationManager($store, $attest)->issue(strictConfirmationEvaluation(strictConfirmationCapability()));

    expect($transition->outcome)->toBe(ApprovalOutcome::IssuanceRefused)
        ->and($transition->refusalReason)->toBe(IssuanceRefusalReason::AttestAppendFailed)
        ->and($transition->receipt)->toBeNull()
        ->and($transition->succeeded())->toBeFalse()
        ->and($attest->calls)->toBe(1)
        ->and($store->all())->toBe([]); // nothing minted
});

it('refuses a strict issuance when no attest backend is configured', function (): void {
    strictPermitSummaries();
    $store = new InMemoryApprovalReceiptStore;

    $transition = strictConfirmationManager($store, attest: null)->issue(strictConfirmationEvaluation(strictConfirmationCapability()));

    expect($transition->outcome)->toBe(ApprovalOutcome::IssuanceRefused)
        ->and($transition->refusalReason)->toBe(IssuanceRefusalReason::AttestNotConfigured)
        ->and($transition->receipt)->toBeNull()
        ->and($store->all())->toBe([]);
});

it('refuses a strict issuance when the summary was not released, without attesting', function (Capability $capability, bool $permit): void {
    if ($permit) {
        strictPermitSummaries();
    }
    $store = new InMemoryApprovalReceiptStore;
    $attest = new RecordingAttestsIssuance;

    $transition = strictConfirmationManager($store, $attest)->issue(strictConfirmationEvaluation($capability));

    expect($transition->outcome)->toBe(ApprovalOutcome::IssuanceRefused)
        ->and($transition->refusalReason)->toBe(IssuanceRefusalReason::SummaryNotReleased)
        ->and($transition->receipt)->toBeNull()
        ->and($store->all())->toBe([])
        ->and($attest->calls)->toBe([]); // never attest an empty display
})->with([
    'ReleaseDenied (described, no policy)' => [fn () => strictConfirmationCapability(), false],
    'NotReleased (no describer)' => [fn () => strictConfirmationCapability(withDescriber: false), true],
]);

// ── a non-strict capability is never touched by the attested path ──────────────────────────────────────

it('does not attest or refuse a non-strict capability, even with a throwing backend or none', function (?AttestsIssuance $attest): void {
    strictPermitSummaries();
    $store = new InMemoryApprovalReceiptStore;

    $transition = strictConfirmationManager($store, $attest)->issue(strictConfirmationEvaluation(strictConfirmationCapability(strict: false)));

    expect($transition->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($store->all())->toHaveCount(1);
    if ($attest instanceof ThrowingAttestsIssuance) {
        expect($attest->calls)->toBe(0); // the throwing backend was never consulted
    }
})->with([
    'no backend' => [fn () => null],
    'throwing backend (must be ignored)' => [fn () => new ThrowingAttestsIssuance],
]);

// ── review lane: the same strict guarantee, anchored on sha256(requestId) ──────────────────────────────

function strictReviewManager(InMemoryReviewRequestStore $store, ?AttestsIssuance $attest): ReviewManager
{
    return new ReviewManager(
        reviews: $store,
        clock: new FrozenClock('2026-08-31 12:00:00'),
        authorizer: new AllowAllReviewAuthorizer,
        defaultTtlSeconds: 900,
        evidence: null,
        invocations: app(InvocationContext::class),
        events: null,
        summaries: new ApproverSummaryMaterializer(app(ContextReleaseManager::class)),
        attestedIssuance: $attest,
    );
}

function strictReviewEvaluation(bool $strict = true, bool $withDescriber = true, int $orderId = 7001): Evaluation
{
    $capability = Capability::usingPolicy('orders.cancel', 'update', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('strict-review-target'))
        ->requiresConfirmation(bindUsing: fn (ActionEnvelope $e, array $t): array => ['bound_order' => $t['order_id']], reason: 'Confirm.');

    if ($withDescriber) {
        $capability = $capability->describeForApprover(fn (ActionEnvelope $e, mixed $t, array $b): string => "Cancel order #{$b['bound_order']}");
    }

    if ($strict) {
        $capability = $capability->requiresAttestedIssuance();
    }

    $envelope = ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', ['order_id' => $orderId], 'tool-call-1'),
        new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']),
    );

    return new Evaluation($envelope, $capability, ['order_id' => $orderId], Decision::requireReview('A human must review.'), EvaluationStage::Proposal);
}

it('mints a strict review request only after attesting the released summary on the request identity', function (): void {
    strictPermitSummaries();
    $store = new InMemoryReviewRequestStore;
    $attest = new RecordingAttestsIssuance(fn (): int => count($store->all()));

    $transition = strictReviewManager($store, $attest)->issue(strictReviewEvaluation());
    $request = $transition->request;

    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($request)->not->toBeNull()
        ->and($attest->calls)->toHaveCount(1)
        ->and($attest->calls[0]['lane'])->toBe(ApprovalLane::Review)
        ->and($attest->calls[0]['identityFingerprint'])->toBe(hash('sha256', $request->id))
        // the attested summary is the SAME one persisted on the request
        ->and($request->approverSummary)->not->toBeNull()
        ->and($attest->calls[0]['summaryFingerprint'])->toBe($request->approverSummary?->fingerprint)
        ->and($attest->storeSizeAtAttest)->toBe([0]) // attested before persistence
        ->and($store->all())->toHaveCount(1)
        // the persisted request is the SAME id that was attested
        ->and(array_values($store->all())[0]->id)->toBe($request->id);
});

it('does not attest or refuse a non-strict review capability, even with a throwing backend or none', function (?AttestsIssuance $attest): void {
    strictPermitSummaries();
    $store = new InMemoryReviewRequestStore;

    $transition = strictReviewManager($store, $attest)->issue(strictReviewEvaluation(strict: false));

    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($store->all())->toHaveCount(1);
    if ($attest instanceof ThrowingAttestsIssuance) {
        expect($attest->calls)->toBe(0);
    }
})->with([
    'no backend' => [fn () => null],
    'throwing backend (must be ignored)' => [fn () => new ThrowingAttestsIssuance],
]);

it('refuses an unreleased strict review summary without ever attesting', function (bool $withDescriber, bool $permit): void {
    if ($permit) {
        strictPermitSummaries();
    }
    $store = new InMemoryReviewRequestStore;
    $attest = new RecordingAttestsIssuance;

    $transition = strictReviewManager($store, $attest)->issue(strictReviewEvaluation(withDescriber: $withDescriber));

    expect($transition->outcome)->toBe(ReviewOutcome::IssuanceRefused)
        ->and($transition->refusalReason)->toBe(IssuanceRefusalReason::SummaryNotReleased)
        ->and($transition->request)->toBeNull()
        ->and($store->all())->toBe([])
        ->and($attest->calls)->toBe([]); // never attest an empty display
})->with([
    'NotReleased (no describer)' => [false, true],
    'ReleaseDenied (described, no policy)' => [true, false],
]);

it('refuses a strict review issuance on attest failure or a missing backend', function (?AttestsIssuance $attest, IssuanceRefusalReason $reason): void {
    strictPermitSummaries();
    $store = new InMemoryReviewRequestStore;

    $transition = strictReviewManager($store, $attest)->issue(strictReviewEvaluation());

    expect($transition->outcome)->toBe(ReviewOutcome::IssuanceRefused)
        ->and($transition->refusalReason)->toBe($reason)
        ->and($transition->request)->toBeNull()
        ->and($transition->succeeded())->toBeFalse()
        ->and($store->all())->toBe([]);
    if ($attest instanceof ThrowingAttestsIssuance) {
        expect($attest->calls)->toBe(1); // the refusal came from a real (attempted) append, not a shortcut
    }
})->with([
    'append fails' => [fn () => new ThrowingAttestsIssuance, IssuanceRefusalReason::AttestAppendFailed],
    'no backend' => [fn () => null, IssuanceRefusalReason::AttestNotConfigured],
]);
