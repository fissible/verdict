<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApproverProvenanceRelease;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Approvals\ApproverSummaryRelease;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Support\ApproverSummary;

// ADR 0038 §1/§6 — the confirmation lane materialises the approver summary at ISSUANCE, inside the frame, from
// the ALREADY-COMPUTED binding, and persists it on the receipt. The typed release state records why a summary is
// or is not present. The summary is the shared Fissible\Verdict\Support\ApproverSummary value (content +
// fingerprint), one shape across both lanes. This slice wires the confirmation lane end to end (materialise →
// receipt/challenge fields → store round-trip); evidence, strict issuance, and review-lane wiring are later.

/** Build an ApprovalManager directly against the given receipt store — no container singleton to fight. */
function summaryManager(ApprovalReceiptStore $store): ApprovalManager
{
    return new ApprovalManager(
        receipts: $store,
        executionContext: app(ApprovalExecutionContext::class),
        clock: app(Clock::class),
        approverProvenance: app(ApproverProvenanceRelease::class),
        invocations: app(InvocationContext::class),
        defaultTtlSeconds: 900,
        authorizer: null,
    );
}

function summaryCapability(bool $withDescriber = true, ?int &$bindCalls = null): Capability
{
    $capability = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('summary-target'))
        ->requiresConfirmation(
            bindUsing: function (ActionEnvelope $envelope, array $target) use (&$bindCalls): array {
                $bindCalls = ($bindCalls ?? 0) + 1; // count how many times the binding is resolved during issue()

                return ['bound_order' => $target['order_id']];
            },
            reason: 'Confirm this cancellation.',
        );

    if ($withDescriber) {
        $capability = $capability->describeForApprover(
            fn (ActionEnvelope $envelope, mixed $target, array $binding): string => "Cancel order #{$binding['bound_order']}",
        );
    }

    return $capability;
}

function summaryEvaluation(Capability $capability, int $orderId = 9001): Evaluation
{
    $envelope = ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', ['order_id' => $orderId], 'tool-call-1'),
        new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']),
    );

    return new Evaluation($envelope, $capability, ['order_id' => $orderId], Decision::requireConfirmation('Confirm.'), EvaluationStage::Execution);
}

// ── the summary is materialised at issuance and persisted on the receipt ──────────────────────────

it('materialises an approver summary at issuance and persists it on the receipt as Released', function (): void {
    $store = new InMemoryApprovalReceiptStore;
    $verdict = summaryManager($store);

    $verdict->issue(summaryEvaluation(summaryCapability(), 9001));
    $receipt = array_values($store->all())[0];

    expect($receipt->approverSummary)->toBeInstanceOf(ApproverSummary::class)
        ->and($receipt->approverSummary->content)->toBe('Cancel order #9001') // the app's binding-informed prose
        ->and($receipt->approverSummary->fingerprint)->toBe(hash('sha256', 'Cancel order #9001'))
        ->and($receipt->approverSummaryRelease)->toBe(ApproverSummaryRelease::Released);
});

it('resolves the binding only once during issuance — the summary and fingerprint share the computed value', function (): void {
    $store = new InMemoryApprovalReceiptStore;
    $bindCalls = 0;
    $verdict = summaryManager($store);

    $verdict->issue(summaryEvaluation(summaryCapability(bindCalls: $bindCalls), 9001));

    // The binding feeds BOTH the binding fingerprint and the approver summary; it must be computed exactly once.
    expect($bindCalls)->toBe(1);
});

it('records NotReleased with no summary when the capability registers no describer', function (): void {
    $store = new InMemoryApprovalReceiptStore;
    $verdict = summaryManager($store);

    $verdict->issue(summaryEvaluation(summaryCapability(withDescriber: false), 9001));
    $receipt = array_values($store->all())[0];

    expect($receipt->approverSummary)->toBeNull()
        ->and($receipt->approverSummaryRelease)->toBe(ApproverSummaryRelease::NotReleased);
});

// ── the challenge surfaces the summary and its release state ──────────────────────────────────────

it('surfaces the approver summary and release state on the challenge', function (): void {
    $store = new InMemoryApprovalReceiptStore;
    $verdict = summaryManager($store);
    $verdict->issue(summaryEvaluation(summaryCapability(), 7001));

    $challenge = $verdict->challengeForToolCall('tool-call-1');

    expect($challenge)->not->toBeNull()
        ->and($challenge->approverSummary?->content)->toBe('Cancel order #7001')
        ->and($challenge->approverSummaryRelease)->toBe(ApproverSummaryRelease::Released);
});

// ── the shared value object is one class across both lanes ────────────────────────────────────────

it('shares one ApproverSummary value shape between the approval and review lanes', function (): void {
    // Both lanes' approverSummary fields are the SAME relocated shared value — Fissible\Verdict\Support\ApproverSummary.
    $reviewType = (new ReflectionProperty(\Fissible\Verdict\Reviews\ReviewRequest::class, 'approverSummary'))->getType();
    $receiptType = (new ReflectionProperty(\Fissible\Verdict\Approvals\ApprovalReceipt::class, 'approverSummary'))->getType();
    assert($reviewType instanceof ReflectionNamedType && $receiptType instanceof ReflectionNamedType);

    expect($reviewType->getName())->toBe(ApproverSummary::class)
        ->and($receiptType->getName())->toBe(ApproverSummary::class);
});

// ── the summary survives the durable round-trip (the default store must not drop it) ──────────────

it('persists the approver summary and release state through the database store', function (): void {
    $connection = app(\Illuminate\Database\DatabaseManager::class)->connection();
    $schema = $connection->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));
    (require __DIR__.'/../../database/migrations/create_verdict_approval_receipts_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_proposal_provenance_to_verdict_approval_receipts_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_approval_context_to_verdict_approval_receipts_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_approver_summary_to_verdict_approval_receipts_table.php.stub')->up();

    $store = new \Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore($connection);

    // Materialise via the manager so the stored row carries a real summary, then hydrate it back from the DB.
    $issued = summaryManager($store)->issue(summaryEvaluation(summaryCapability(), 4242));
    $hydrated = $store->find($issued->receipt->id);

    $schema->dropIfExists(verdictTable('approvals'));

    expect($hydrated?->approverSummary?->content)->toBe('Cancel order #4242')
        ->and($hydrated?->approverSummary?->fingerprint)->toBe(hash('sha256', 'Cancel order #4242'))
        ->and($hydrated?->approverSummaryRelease)->toBe(ApproverSummaryRelease::Released);
});

// ── durable degradation: a pre-migration table keeps working, and legacy rows are NULL, not NotReleased ──

it('degrades gracefully against a table lacking the approver-summary columns, hydrating a null release', function (): void {
    // An upgrading deployment whose approvals table predates the summary columns must not fail on issue, and a
    // row without the columns hydrates as a PRE-FEATURE record — a null release state, distinct from NotReleased
    // ("a summary was not produced"). ADR 0038 §3.
    $connection = app(\Illuminate\Database\DatabaseManager::class)->connection();
    $schema = $connection->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));
    (require __DIR__.'/../../database/migrations/create_verdict_approval_receipts_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_proposal_provenance_to_verdict_approval_receipts_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_approval_context_to_verdict_approval_receipts_table.php.stub')->up();
    // Deliberately NOT running add_approver_summary_… — the lagging-table case.

    $store = new \Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore($connection);
    $issued = summaryManager($store)->issue(summaryEvaluation(summaryCapability(), 4242)); // has a describer
    $hydrated = $store->find($issued->receipt->id);

    $schema->dropIfExists(verdictTable('approvals'));

    // The summary is dropped (no column to keep it), and the release is NULL — a storage era, never NotReleased.
    expect($issued->receipt)->not->toBeNull()
        ->and($hydrated?->approverSummary)->toBeNull()
        ->and($hydrated?->approverSummaryRelease)->toBeNull();
});
