<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovedToolCalls;
use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\ApproverProvenanceRelease;
use Fissible\Verdict\Approvals\ApproverSummaryMaterializer;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalOperation;
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewManager;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\Verdict\Testing\AllowAllReviewAuthorizer;
use Fissible\Verdict\Tests\Support\FrozenClock;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

// ADR 0038 §4/§5 — issuance, the human approve/reject, and successful consumption emit new POST-COMMIT
// operational events, recorded through EvidenceWriter::recordApprovalOperation(). The events are observational
// (Normal tier): emitted AFTER the store's committed transition, an evidence-write failure is caught and reported
// (EvidenceWriteFailed) but NEVER undoes the operation, and no event fires for a failed transition. The identity
// anchor is sha256(receiptId) for the confirmation lane and sha256(requestId) for the review lane — ALWAYS present
// and DISTINCT from the binding fingerprint (the #297 reconciliation: a binding is not an identity). The summary
// fingerprint rides along only when the approver summary was Released.

// ── confirmation-lane harness ────────────────────────────────────────────────────────────────────────

function opConfirmationManager(
    InMemoryApprovalReceiptStore $store,
    ?EvidenceWriter $evidence,
    ?Dispatcher $events = null,
): ApprovalManager {
    return new ApprovalManager(
        receipts: $store,
        executionContext: app(ApprovalExecutionContext::class),
        clock: app(Clock::class),
        approverProvenance: app(ApproverProvenanceRelease::class),
        invocations: app(InvocationContext::class),
        defaultTtlSeconds: 900,
        authorizer: new AllowAllApprovalAuthorizer,
        summaries: new ApproverSummaryMaterializer(app(ContextReleaseManager::class)),
        evidence: $evidence,
        events: $events,
    );
}

function opConfirmationCapability(bool $withDescriber = true): Capability
{
    $capability = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('op-target'))
        ->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $envelope, array $target): array => ['bound_order' => $target['order_id']],
            reason: 'Confirm this cancellation.',
        );

    if ($withDescriber) {
        $capability = $capability->describeForApprover(
            fn (ActionEnvelope $e, mixed $t, array $b): string => "Cancel order #{$b['bound_order']}",
        );
    }

    return $capability;
}

function opConfirmationEvaluation(Capability $capability, int $orderId = 9001): Evaluation
{
    $envelope = ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', ['order_id' => $orderId], 'tool-call-1'),
        new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']),
    );

    return new Evaluation($envelope, $capability, ['order_id' => $orderId], Decision::requireConfirmation('Confirm.'), EvaluationStage::Execution);
}

/** Register the approver-audience policy so the summary materialises as Released (a summary fingerprint exists). */
function opPermitSummaries(): void
{
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Untrusted, Trust::Trusted),
    );
}

// ── confirmation lane: each of the four operations emits one correctly-anchored event ──────────────────

it('emits an Issued event anchored on sha256(receiptId), carrying the released summary fingerprint and invocation id', function (): void {
    opPermitSummaries();
    $store = new InMemoryApprovalReceiptStore;
    $recorder = new InMemoryEvidenceRecorder;
    $manager = opConfirmationManager($store, $recorder);

    // Wrap issuance in an invocation frame so the emitted event carries the current invocation id.
    $transition = app(InvocationContext::class)->within('inv-42', fn () => $manager->issue(opConfirmationEvaluation(opConfirmationCapability())));
    $receipt = $transition->receipt;

    expect($recorder->operations())->toHaveCount(1);
    $event = $recorder->operations()[0];
    expect($event->lane)->toBe(ApprovalLane::Confirmation)
        ->and($event->operation)->toBe(ApprovalOperation::Issued)
        ->and($event->capability)->toBe('orders.cancel')
        ->and($event->identityFingerprint)->toBe(hash('sha256', $receipt->id))
        ->and($event->summaryFingerprint)->toBe($receipt->approverSummary?->fingerprint)
        ->and($event->summaryFingerprint)->not->toBeNull() // Released → present
        ->and($event->invocationId)->toBe('inv-42');
});

it('emits a null summary fingerprint when the summary was ReleaseDenied (no policy) or NotReleased (no describer)', function (Capability $capability, bool $permit): void {
    if ($permit) {
        opPermitSummaries();
    }
    $store = new InMemoryApprovalReceiptStore;
    $recorder = new InMemoryEvidenceRecorder;

    $transition = opConfirmationManager($store, $recorder)->issue(opConfirmationEvaluation($capability));

    $event = $recorder->operations()[0];
    expect($event->summaryFingerprint)->toBeNull()
        ->and($event->identityFingerprint)->toBe(hash('sha256', $transition->receipt->id));
})->with([
    'ReleaseDenied (described, no policy)' => [fn () => opConfirmationCapability(), false],
    'NotReleased (no describer)' => [fn () => opConfirmationCapability(withDescriber: false), true],
]);

it('emits an Approved event on the receipt identity for the human approval', function (): void {
    $store = new InMemoryApprovalReceiptStore;
    $recorder = new InMemoryEvidenceRecorder;
    $manager = opConfirmationManager($store, $recorder);

    $issued = $manager->issue(opConfirmationEvaluation(opConfirmationCapability(), 111));
    $manager->approve($issued->receipt->id, 'tool-call-1', 'reviewer:1');

    $event = $recorder->operations()[1];
    expect($event->operation)->toBe(ApprovalOperation::Approved)
        ->and($event->lane)->toBe(ApprovalLane::Confirmation)
        ->and($event->identityFingerprint)->toBe(hash('sha256', $issued->receipt->id));
});

it('emits a Rejected event on the receipt identity for the human rejection', function (): void {
    $store = new InMemoryApprovalReceiptStore;
    $recorder = new InMemoryEvidenceRecorder;
    $manager = opConfirmationManager($store, $recorder);

    $issued = $manager->issue(opConfirmationEvaluation(opConfirmationCapability(), 222));
    $manager->reject($issued->receipt->id, 'tool-call-1', 'reviewer:2');

    $event = $recorder->operations()[1];
    expect($event->operation)->toBe(ApprovalOperation::Rejected)
        ->and($event->lane)->toBe(ApprovalLane::Confirmation)
        ->and($event->identityFingerprint)->toBe(hash('sha256', $issued->receipt->id));
});

it('emits a Consumed event when an approved receipt is spent within approved tool calls', function (): void {
    $store = new InMemoryApprovalReceiptStore;
    $recorder = new InMemoryEvidenceRecorder;
    $manager = opConfirmationManager($store, $recorder);
    $capability = opConfirmationCapability();

    $issued = $manager->issue(opConfirmationEvaluation($capability, 333));
    $manager->approve($issued->receipt->id, 'tool-call-1', 'reviewer:1');
    $manager->withinApprovedToolCalls(
        ApprovedToolCalls::of(['tool-call-1']),
        fn () => $manager->consume(opConfirmationEvaluation($capability, 333)),
    );

    $consumed = $recorder->operations()[array_key_last($recorder->operations())];
    expect($consumed->operation)->toBe(ApprovalOperation::Consumed)
        ->and($consumed->lane)->toBe(ApprovalLane::Confirmation)
        ->and($consumed->identityFingerprint)->toBe(hash('sha256', $issued->receipt->id));
});

// ── post-commit ordering: the event is emitted AFTER the store has persisted the transition ────────────

it('emits the operational event only after the store has committed the receipt', function (): void {
    $store = new InMemoryApprovalReceiptStore;
    // The inspecting writer probes the store at the exact moment the operational event is recorded. If the
    // manager emitted BEFORE calling the store, the receipt would not yet be present — count would be 0.
    $writer = new InspectingApprovalOperationWriter(fn (): int => count($store->all()));

    opConfirmationManager($store, $writer)->issue(opConfirmationEvaluation(opConfirmationCapability(), 444));

    expect($writer->storeCountsAtRecord)->toBe([1]); // the receipt was already persisted when the event was recorded
});

// ── observational: a write failure is caught, reported, and never undoes the committed operation ───────

it('catches an evidence-write failure on issuance, reports it, and keeps the committed receipt', function (): void {
    Event::fake([EvidenceWriteFailed::class]);
    $store = new InMemoryApprovalReceiptStore;

    $transition = opConfirmationManager($store, new ThrowingApprovalOperationWriter, app(Dispatcher::class))
        ->issue(opConfirmationEvaluation(opConfirmationCapability(), 666));

    expect($transition->succeeded())->toBeTrue()
        ->and($store->find($transition->receipt->id))->not->toBeNull(); // durable despite the write throwing
    Event::assertDispatched(EvidenceWriteFailed::class);
});

it('catches an evidence-write failure on approval and keeps the approved receipt', function (): void {
    Event::fake([EvidenceWriteFailed::class]);
    $store = new InMemoryApprovalReceiptStore;
    // Issue with a working recorder, then swap in a throwing one for the decision step.
    $issued = opConfirmationManager($store, new InMemoryEvidenceRecorder)
        ->issue(opConfirmationEvaluation(opConfirmationCapability(), 777));

    $transition = opConfirmationManager($store, new ThrowingApprovalOperationWriter, app(Dispatcher::class))
        ->approve($issued->receipt->id, 'tool-call-1', 'reviewer:1');

    expect($transition->succeeded())->toBeTrue();
    Event::assertDispatched(EvidenceWriteFailed::class);
});

it('does not propagate even when the EvidenceWriteFailed listener itself throws', function (): void {
    // The observational guarantee must survive a failing ALERT path too: the record throws, and the failure
    // event's own listener throws. Neither may escape after the store has committed. (Mirrors AttestEvidence
    // Recorder, which contains its ChainWriteFailed dispatch for the same reason.)
    app('events')->listen(EvidenceWriteFailed::class, function (): void {
        throw new RuntimeException('alert listener is down');
    });
    $store = new InMemoryApprovalReceiptStore;

    $transition = opConfirmationManager($store, new ThrowingApprovalOperationWriter, app(Dispatcher::class))
        ->issue(opConfirmationEvaluation(opConfirmationCapability(), 999));

    expect($transition->succeeded())->toBeTrue()
        ->and($store->find($transition->receipt->id))->not->toBeNull();
});

it('does not propagate a throwing review EvidenceWriteFailed listener', function (): void {
    app('events')->listen(EvidenceWriteFailed::class, function (): void {
        throw new RuntimeException('alert listener is down');
    });
    $store = new InMemoryReviewRequestStore;

    $transition = opReviewManager($store, new ThrowingApprovalOperationWriter, app(Dispatcher::class))
        ->issue(opReviewEvaluation());

    expect($transition->succeeded())->toBeTrue()
        ->and($transition->request)->not->toBeNull();
});

// ── no event for a non-happening operation: failed transitions and idempotent re-issue ─────────────────

it('does not emit an operational event for a failed transition', function (): void {
    $store = new InMemoryApprovalReceiptStore;
    $recorder = new InMemoryEvidenceRecorder;
    $manager = opConfirmationManager($store, $recorder);
    $manager->issue(opConfirmationEvaluation(opConfirmationCapability(), 444)); // 1 Issued event

    $manager->approve(str_repeat('z', 64), 'tool-call-1', 'reviewer:1'); // NotFound → no event

    expect($recorder->operations())->toHaveCount(1)
        ->and($recorder->operations()[0]->operation)->toBe(ApprovalOperation::Issued);
});

it('does not emit a second Issued event when re-issuing returns the existing receipt', function (): void {
    $store = new InMemoryApprovalReceiptStore;
    $recorder = new InMemoryEvidenceRecorder;
    $manager = opConfirmationManager($store, $recorder);
    $capability = opConfirmationCapability();

    $manager->issue(opConfirmationEvaluation($capability, 888));
    $manager->issue(opConfirmationEvaluation($capability, 888)); // same binding → Existing, not a fresh Issued

    expect($recorder->operations())->toHaveCount(1);
});

it('emits nothing when no evidence writer is provided (the parameter is optional)', function (): void {
    $store = new InMemoryApprovalReceiptStore;

    $transition = opConfirmationManager($store, evidence: null)->issue(opConfirmationEvaluation(opConfirmationCapability(), 555));

    expect($transition->succeeded())->toBeTrue(); // the operation still happens, it is simply unrecorded
});

// ── review lane: identity anchor is sha256(requestId), DISTINCT from the binding fingerprint ───────────

function opReviewManager(InMemoryReviewRequestStore $store, ?EvidenceWriter $evidence, ?Dispatcher $events = null): ReviewManager
{
    return new ReviewManager(
        reviews: $store,
        clock: new FrozenClock('2026-08-31 12:00:00'),
        authorizer: new AllowAllReviewAuthorizer,
        defaultTtlSeconds: 900,
        evidence: $evidence,
        invocations: app(InvocationContext::class),
        events: $events,
    );
}

function opReviewEvaluation(int $orderId = 7001): Evaluation
{
    $capability = Capability::usingPolicy('orders.cancel', 'update', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('op-review-target'))
        ->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $e, array $target): array => ['bound_order' => $target['order_id']],
            reason: 'Confirm.',
        );

    $envelope = ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', ['order_id' => $orderId], 'tool-call-1'),
        new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']),
    );

    return new Evaluation($envelope, $capability, ['order_id' => $orderId], Decision::requireReview('A human must review.'), EvaluationStage::Proposal);
}

it('emits a review Issued event anchored on sha256(requestId), NOT the binding fingerprint, with a null summary', function (): void {
    $store = new InMemoryReviewRequestStore;
    $recorder = new InMemoryEvidenceRecorder;

    $transition = opReviewManager($store, $recorder)->issue(opReviewEvaluation());
    $request = $transition->request;

    expect($recorder->operations())->toHaveCount(1);
    $event = $recorder->operations()[0];
    expect($event->lane)->toBe(ApprovalLane::Review)
        ->and($event->operation)->toBe(ApprovalOperation::Issued)
        ->and($event->identityFingerprint)->toBe(hash('sha256', $request->id))
        // The reconciliation: the anchor is the REQUEST id fingerprint, never the binding fingerprint.
        ->and($event->identityFingerprint)->not->toBe($request->bindingFingerprint)
        ->and($request->id)->not->toBe($request->bindingFingerprint)
        // The review-lane summary producer lands in a later slice; no summary is released here yet.
        ->and($event->summaryFingerprint)->toBeNull();
});

it('anchors review approve, reject, and consume on sha256(requestId), never the binding fingerprint', function (): void {
    // approve → consume on one request…
    $store = new InMemoryReviewRequestStore;
    $recorder = new InMemoryEvidenceRecorder;
    $manager = opReviewManager($store, $recorder);
    $issued = $manager->issue(opReviewEvaluation());
    $anchor = hash('sha256', $issued->request->id);
    $manager->approve($issued->request->id, 'reviewer:9');
    $manager->consume(opReviewEvaluation());

    $operations = $recorder->operations();
    expect($operations)->toHaveCount(3)
        ->and($operations[1]->operation)->toBe(ApprovalOperation::Approved)
        ->and($operations[1]->lane)->toBe(ApprovalLane::Review)
        ->and($operations[1]->identityFingerprint)->toBe($anchor)
        ->and($operations[1]->identityFingerprint)->not->toBe($issued->request->bindingFingerprint)
        ->and($operations[2]->operation)->toBe(ApprovalOperation::Consumed)
        ->and($operations[2]->lane)->toBe(ApprovalLane::Review)
        ->and($operations[2]->identityFingerprint)->toBe($anchor)
        ->and($operations[2]->identityFingerprint)->not->toBe($issued->request->bindingFingerprint);

    // …and reject on a separate request.
    $store2 = new InMemoryReviewRequestStore;
    $recorder2 = new InMemoryEvidenceRecorder;
    $manager2 = opReviewManager($store2, $recorder2);
    $issued2 = $manager2->issue(opReviewEvaluation(7002));
    $manager2->reject($issued2->request->id, 'reviewer:9');

    $reject = $recorder2->operations()[1];
    expect($reject->operation)->toBe(ApprovalOperation::Rejected)
        ->and($reject->lane)->toBe(ApprovalLane::Review)
        ->and($reject->identityFingerprint)->toBe(hash('sha256', $issued2->request->id))
        ->and($reject->identityFingerprint)->not->toBe($issued2->request->bindingFingerprint);
});

it('does not emit a review operational event for a failed decision', function (): void {
    $store = new InMemoryReviewRequestStore;
    $recorder = new InMemoryEvidenceRecorder;
    $manager = opReviewManager($store, $recorder);
    $manager->issue(opReviewEvaluation()); // 1 Issued

    $manager->approve(str_repeat('z', 64), 'reviewer:9'); // NotFound → no event

    expect($recorder->operations())->toHaveCount(1);
});

// ── test doubles ───────────────────────────────────────────────────────────────────────────────────────

/** An EvidenceWriter whose operational-event write always throws — proves the observational swallow. */
final class ThrowingApprovalOperationWriter implements EvidenceWriter
{
    public function record(DecisionEvidence $evidence): void {}

    public function recordRelease(ContextReleaseEvidence $evidence): void {}

    public function recordProvenance(ProvenanceEntry $entry): void {}

    public function recordDerivation(ProvenanceDerivation $derivation): void {}

    public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void
    {
        throw new RuntimeException('operational evidence backend is down');
    }
}

/** An EvidenceWriter that probes external state at the moment recordApprovalOperation() is called. */
final class InspectingApprovalOperationWriter implements EvidenceWriter
{
    /** @var list<int> */
    public array $storeCountsAtRecord = [];

    /** @param Closure(): int $probe */
    public function __construct(private Closure $probe) {}

    public function record(DecisionEvidence $evidence): void {}

    public function recordRelease(ContextReleaseEvidence $evidence): void {}

    public function recordProvenance(ProvenanceEntry $entry): void {}

    public function recordDerivation(ProvenanceDerivation $derivation): void {}

    public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void
    {
        $this->storeCountsAtRecord[] = ($this->probe)();
    }
}
