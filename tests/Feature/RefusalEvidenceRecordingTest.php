<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\ApproverProvenanceRelease;
use Fissible\Verdict\Approvals\ApproverSummaryMaterializer;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryConsumedBindingGuardStore;
use Fissible\Verdict\Approvals\IssuanceRefusalReason;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\AttestsIssuance;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\RecordsApprovalRefusals;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;
use Fissible\Verdict\Evidence\ApprovalRefusalEvidence;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Support\ApproverSummary;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;

// ADR 0039 pinned regressions #10 and #15: a REFUSED issuance produces a refusal-operation evidence
// record (no receipt) anchored on the binding's keyless guard digest, carrying the refusal reason;
// PreviouslyConsumed records through it and it subsumes the attest-refusals. The record is
// deduplicated per digest (one row per binding) with an attempt count, so a replay flood cannot grow
// evidence without bound. A failing writer never blocks the caller. A recorder that does not opt into
// RecordsApprovalRefusals simply records nothing. Confirmation lane only (review lane is a follow-up).

const RE_TC = 'tool-call-1';
const RE_CAP = 'orders.cancel';

function reManager(
    ApprovalReceiptStore $store,
    ?EvidenceWriter $evidence,
    ?object $attestedIssuance = null,
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
        attestedIssuance: $attestedIssuance,
    );
}

function reCapability(bool $attested = false): Capability
{
    $capability = Capability::usingPolicy(RE_CAP, 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('re-target'))
        ->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $e, array $target): array => ['bound_order' => $target['order_id']],
            reason: 'Confirm this cancellation.',
        )
        ->describeForApprover(fn (ActionEnvelope $e, mixed $t, array $b): string => "Cancel order #{$b['bound_order']}");

    return $attested ? $capability->requiresAttestedIssuance() : $capability;
}

function reEvaluation(Capability $capability, int $orderId = 9001): Evaluation
{
    $envelope = ActionEnvelope::wrap(
        new ActionProposal(RE_CAP, ['order_id' => $orderId], RE_TC),
        new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']),
    );

    return new Evaluation($envelope, $capability, ['order_id' => $orderId], Decision::requireConfirmation('Confirm.'), EvaluationStage::Execution);
}

/** Release the approver summary so a strict issuance passes the summary gate (isolates AttestNotConfigured). */
function rePermitSummaries(): void
{
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Untrusted, Trust::Trusted),
    );
}

/**
 * Drive a binding to the post-prune PreviouslyConsumed state: issue, approve, consume (writes the
 * guard), prune the receipt payload (keeps the guard). Returns [manager, evaluation, bindingDigestHex].
 *
 * @return array{0: ApprovalManager, 1: Evaluation, 2: string}
 */
function rePreviouslyConsumed(?EvidenceWriter $evidence, ?Dispatcher $events = null): array
{
    $guards = new InMemoryConsumedBindingGuardStore;
    $store = new InMemoryApprovalReceiptStore(guards: $guards);
    $manager = reManager($store, $evidence, events: $events);
    $evaluation = reEvaluation(reCapability());

    $issued = $manager->issue($evaluation);
    expect($issued->outcome)->toBe(ApprovalOutcome::Issued);
    $receipt = $issued->receipt;
    $at = app(Clock::class)->now();
    expect($store->approve($receipt->id, RE_TC, 'human', $at)->outcome)->toBe(ApprovalOutcome::Approved);
    expect($store->consume(RE_TC, $receipt->bindingFingerprint, $at)->outcome)->toBe(ApprovalOutcome::Consumed);
    $store->pruneConsumedPayload($at->modify('+1 day'));

    $digestHex = bin2hex(ConsumedBindingGuard::digest(RE_TC, RE_CAP, $receipt->bindingFingerprint));

    return [$manager, $evaluation, $digestHex];
}

/** Captures every recordApprovalRefusal() call verbatim (no dedup) — proves what the manager emits. */
function reCapturingWriter(): EvidenceWriter
{
    return new class implements EvidenceWriter, RecordsApprovalRefusals
    {
        /** @var list<ApprovalRefusalEvidence> */
        public array $refusals = [];

        public function record(DecisionEvidence $evidence): void {}

        public function recordRelease(ContextReleaseEvidence $evidence): void {}

        public function recordProvenance(ProvenanceEntry $entry): void {}

        public function recordDerivation(ProvenanceDerivation $derivation): void {}

        public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void {}

        public function recordApprovalRefusal(ApprovalRefusalEvidence $evidence): void
        {
            $this->refusals[] = $evidence;
        }
    };
}

// ── #10: PreviouslyConsumed records a refusal anchored on the guard digest ───────────────────────────

it('records a PreviouslyConsumed refusal anchored on the binding guard digest, with no receipt', function (): void {
    $writer = reCapturingWriter();
    [$manager, $evaluation, $digestHex] = rePreviouslyConsumed($writer);

    $refused = app(InvocationContext::class)->within('inv-refuse', fn () => $manager->issue($evaluation));

    expect($refused->outcome)->toBe(ApprovalOutcome::IssuanceRefused)
        ->and($refused->refusalReason)->toBe(IssuanceRefusalReason::PreviouslyConsumed)
        ->and($writer->refusals)->toHaveCount(1);

    $refusal = $writer->refusals[0];
    expect($refusal->bindingDigest)->toBe($digestHex)
        ->and($refusal->reason)->toBe(IssuanceRefusalReason::PreviouslyConsumed)
        ->and($refusal->lane)->toBe(ApprovalLane::Confirmation)
        ->and($refusal->capability)->toBe(RE_CAP)
        ->and($refusal->invocationId)->toBe('inv-refuse');
});

// ── #10: the attest-refusals record through the SAME contract ────────────────────────────────────────

it('records an attest-refusal (AttestNotConfigured) through the refusal contract', function (): void {
    rePermitSummaries();
    $writer = reCapturingWriter();
    $store = new InMemoryApprovalReceiptStore(guards: new InMemoryConsumedBindingGuardStore);
    // A capability that requires attested issuance, but no attester is configured on the manager.
    $manager = reManager($store, $writer, attestedIssuance: null);
    $evaluation = reEvaluation(reCapability(attested: true));

    $refused = $manager->issue($evaluation);

    expect($refused->outcome)->toBe(ApprovalOutcome::IssuanceRefused)
        ->and($refused->refusalReason)->toBe(IssuanceRefusalReason::AttestNotConfigured)
        ->and($writer->refusals)->toHaveCount(1)
        ->and($writer->refusals[0]->reason)->toBe(IssuanceRefusalReason::AttestNotConfigured)
        ->and($writer->refusals[0]->lane)->toBe(ApprovalLane::Confirmation)
        ->and($writer->refusals[0]->bindingDigest)->toMatch('/^[0-9a-f]{64}$/'); // anchored on a binding digest (exact value pinned by the PreviouslyConsumed test)
});

// ── #15: refusal recording is flood-bounded (dedup per digest, attempt count) ────────────────────────

it('deduplicates refusal records per digest with an attempt count (flood-bound)', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    [$manager, $evaluation, $digestHex] = rePreviouslyConsumed($recorder);

    foreach (range(1, 3) as $ignored) {
        expect($manager->issue($evaluation)->outcome)->toBe(ApprovalOutcome::IssuanceRefused);
    }

    expect($recorder->recordedRefusals())->toHaveCount(1)
        ->and($recorder->refusalAttempts($digestHex))->toBe(3);
});

// ── a failing writer never blocks the caller ─────────────────────────────────────────────────────────

it('swallows a failing refusal writer and still returns the refusal, dispatching EvidenceWriteFailed', function (): void {
    Event::fake([EvidenceWriteFailed::class]);
    $writer = new class implements EvidenceWriter, RecordsApprovalRefusals
    {
        public function record(DecisionEvidence $evidence): void {}

        public function recordRelease(ContextReleaseEvidence $evidence): void {}

        public function recordProvenance(ProvenanceEntry $entry): void {}

        public function recordDerivation(ProvenanceDerivation $derivation): void {}

        public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void {}

        public function recordApprovalRefusal(ApprovalRefusalEvidence $evidence): void
        {
            throw new RuntimeException('refusal evidence backend is down');
        }
    };
    // Inject the faked dispatcher (resolved after Event::fake) so the manager's injected-dispatcher
    // swallow path (the same $this->events?->dispatch(...) recordApprovalOperation uses) is observable.
    [$manager, $evaluation] = rePreviouslyConsumed($writer, app(Dispatcher::class));

    $refused = $manager->issue($evaluation);

    expect($refused->outcome)->toBe(ApprovalOutcome::IssuanceRefused)
        ->and($refused->refusalReason)->toBe(IssuanceRefusalReason::PreviouslyConsumed);
    Event::assertDispatched(EvidenceWriteFailed::class);
});

// ── a successful issuance records no refusal; a non-opting recorder records nothing ──────────────────

it('records no refusal for a successful issuance', function (): void {
    $writer = reCapturingWriter();
    $store = new InMemoryApprovalReceiptStore(guards: new InMemoryConsumedBindingGuardStore);
    $manager = reManager($store, $writer);

    expect($manager->issue(reEvaluation(reCapability()))->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($writer->refusals)->toBe([]);
});

it('does not attempt to record when the writer does not opt into RecordsApprovalRefusals', function (): void {
    // A plain EvidenceWriter (no RecordsApprovalRefusals) must be left alone on the refusal path.
    $writer = new class implements EvidenceWriter
    {
        public function record(DecisionEvidence $evidence): void {}

        public function recordRelease(ContextReleaseEvidence $evidence): void {}

        public function recordProvenance(ProvenanceEntry $entry): void {}

        public function recordDerivation(ProvenanceDerivation $derivation): void {}

        public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void {}
    };
    [$manager, $evaluation] = rePreviouslyConsumed($writer);

    expect($manager->issue($evaluation)->outcome)->toBe(ApprovalOutcome::IssuanceRefused);
});

// ── the remaining attest-refusals record through the SAME contract (distinct return points) ──────────

/** An attester whose append always throws — reaches the AttestAppendFailed return point. */
function reThrowingAttester(): AttestsIssuance
{
    return new class implements AttestsIssuance
    {
        public function attestIssuedSummary(ApprovalLane $lane, string $identityFingerprint, ApproverSummary $summary): void
        {
            throw new RuntimeException('attest backend is down');
        }
    };
}

/** An ApprovalReceiptStore that is NOT IssuesAdmittedReceipts — reaches the AttestOrderingUnsupported point. */
function reNonAdmittingStore(): ApprovalReceiptStore
{
    return new class(new InMemoryApprovalReceiptStore) implements ApprovalReceiptStore
    {
        public function __construct(private InMemoryApprovalReceiptStore $inner) {}

        public function issue(ApprovalReceipt $receipt): ApprovalTransition
        {
            return $this->inner->issue($receipt);
        }

        public function findForToolCall(string $toolCallId): ?ApprovalReceipt
        {
            return $this->inner->findForToolCall($toolCallId);
        }

        public function find(string $receiptId): ?ApprovalReceipt
        {
            return $this->inner->find($receiptId);
        }

        public function approve(string $receiptId, string $toolCallId, string $approvedBy, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->approve($receiptId, $toolCallId, $approvedBy, $at);
        }

        public function reject(string $receiptId, string $toolCallId, string $rejectedBy, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->reject($receiptId, $toolCallId, $rejectedBy, $at);
        }

        public function validate(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->validate($toolCallId, $bindingFingerprint, $at);
        }

        public function consume(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
        {
            return $this->inner->consume($toolCallId, $bindingFingerprint, $at);
        }
    };
}

it('records a SummaryNotReleased attest-refusal through the contract', function (): void {
    // Attested capability but no release policy — the summary gate refuses first (a distinct return point).
    $writer = reCapturingWriter();
    $store = new InMemoryApprovalReceiptStore(guards: new InMemoryConsumedBindingGuardStore);
    $manager = reManager($store, $writer, attestedIssuance: reThrowingAttester());

    $refused = $manager->issue(reEvaluation(reCapability(attested: true)));

    expect($refused->refusalReason)->toBe(IssuanceRefusalReason::SummaryNotReleased)
        ->and($writer->refusals)->toHaveCount(1)
        ->and($writer->refusals[0]->reason)->toBe(IssuanceRefusalReason::SummaryNotReleased)
        ->and($writer->refusals[0]->bindingDigest)->toMatch('/^[0-9a-f]{64}$/');
});

it('records an AttestOrderingUnsupported attest-refusal through the contract', function (): void {
    // Summary released + attester present, but the store cannot honour check->attest->persist.
    rePermitSummaries();
    $writer = reCapturingWriter();
    $manager = reManager(reNonAdmittingStore(), $writer, attestedIssuance: reThrowingAttester());

    $refused = $manager->issue(reEvaluation(reCapability(attested: true)));

    expect($refused->refusalReason)->toBe(IssuanceRefusalReason::AttestOrderingUnsupported)
        ->and($writer->refusals)->toHaveCount(1)
        ->and($writer->refusals[0]->reason)->toBe(IssuanceRefusalReason::AttestOrderingUnsupported);
});

it('records an AttestAppendFailed attest-refusal through the contract (nested issueAttested path)', function (): void {
    // Summary released + attester present + admitting store, but the attest append throws — the refusal
    // returns from issueAttested(), a different code path than the pre-checks.
    rePermitSummaries();
    $writer = reCapturingWriter();
    $store = new InMemoryApprovalReceiptStore(guards: new InMemoryConsumedBindingGuardStore);
    $manager = reManager($store, $writer, attestedIssuance: reThrowingAttester());

    $refused = $manager->issue(reEvaluation(reCapability(attested: true)));

    expect($refused->refusalReason)->toBe(IssuanceRefusalReason::AttestAppendFailed)
        ->and($writer->refusals)->toHaveCount(1)
        ->and($writer->refusals[0]->reason)->toBe(IssuanceRefusalReason::AttestAppendFailed);
});

// ── the durable recorder deduplicates by digest with a persisted attempt count (real DB) ─────────────

it('deduplicates refusal rows per digest in the database recorder with an attempt count', function (): void {
    $migration = require __DIR__.'/../../database/migrations/create_verdict_approval_refusals_table.php.stub';
    $migration->up();

    try {
        $recorder = new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());
        $digest = str_repeat('a', 64);
        $evidence = new ApprovalRefusalEvidence(
            lane: ApprovalLane::Confirmation,
            reason: IssuanceRefusalReason::PreviouslyConsumed,
            capability: RE_CAP,
            bindingDigest: $digest,
            occurredAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
            invocationId: null,
        );

        $recorder->recordApprovalRefusal($evidence);
        $recorder->recordApprovalRefusal($evidence);
        $recorder->recordApprovalRefusal($evidence);

        $rows = app(DatabaseManager::class)->connection()->table('verdict_approval_refusals')->get();

        expect($rows)->toHaveCount(1)
            ->and((int) $rows[0]->attempt_count)->toBe(3)
            ->and($rows[0]->refusal_reason)->toBe('previously_consumed')
            ->and(rtrim((string) $rows[0]->binding_digest))->toBe($digest);
    } finally {
        app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists('verdict_approval_refusals');
    }
});
