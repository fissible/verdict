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
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\ApproverProvenanceRelease;
use Fissible\Verdict\Approvals\ApproverSummaryMaterializer;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\DatabaseConsumedBindingGuardStore;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\IssuanceRefusalReason;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\Verdict\VerdictManager;
use Illuminate\Database\DatabaseManager;

// Slice 4 of ADR 0039: issue() consults the guard. The row-check runs first and unchanged; ONLY when
// no receipt row exists for the binding (the state a future prune leaves) and a guard is present does
// issue() refuse with the new store outcome PreviouslyConsumed, which the manager surfaces as
// IssuanceRefused + IssuanceRefusalReason::PreviouslyConsumed. Pre-pruning this is dormant in normal
// flow (a consumed binding still has its Consumed row, caught first by the row-check), so it is tested
// by seeding the pruned state directly. Self-contained: relies only on Pest.php globals.

const ISSUE_GUARD_TABLE = 'verdict_consumed_binding_guards';

function issueGuardTime(string $at = '2026-08-01 12:00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

function issueGuardReceipt(string $toolCallId, string $capability, string $fingerprint, string $createdAt = '2026-08-01 12:00:00', string $expiresIn = '+1 hour'): ApprovalReceipt
{
    $now = issueGuardTime($createdAt);

    return new ApprovalReceipt(
        id: bin2hex(random_bytes(16)),
        toolCallId: $toolCallId,
        capability: $capability,
        bindingFingerprint: $fingerprint,
        provenance: null,
        approvalContext: null,
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm.',
        expiresAt: $now->modify($expiresIn),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $now,
        updatedAt: $now,
    );
}

function issueGuardRecordingStore(): ConsumedBindingGuardStore
{
    return new class implements ConsumedBindingGuardStore
    {
        /** @var list<string> */
        public array $remembered = [];

        /** @var list<string> digests queried via has() */
        public array $reads = [];

        public function has(string $digest): bool
        {
            $this->reads[] = $digest;

            return in_array($digest, $this->remembered, true);
        }

        public function remember(string $digest, DateTimeInterface $consumedAt): void
        {
            $this->remembered[] = $digest;
        }
    };
}

function issueGuardStoreFor(string $driver, ConsumedBindingGuardStore $guards): ApprovalReceiptStore
{
    return $driver === 'database'
        ? new DatabaseApprovalReceiptStore(connection: app(DatabaseManager::class)->connection(), guards: $guards)
        : new InMemoryApprovalReceiptStore(guards: $guards);
}

function issueGuardManager(ApprovalReceiptStore $store): ApprovalManager
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
        attestedIssuance: null,
    );
}

function issueGuardCapability(): Capability
{
    return Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('issue-guard-target'))
        ->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $e, array $target): array => ['bound_order' => $target['order_id']],
            reason: 'Confirm this cancellation.',
        )
        ->describeForApprover(fn (ActionEnvelope $e, mixed $t, array $b): string => "Cancel order #{$b['bound_order']}");
}

function issueGuardEvaluation(Capability $capability, int $orderId = 9001): Evaluation
{
    $envelope = ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', ['order_id' => $orderId], 'tool-call-issue-guard'),
        new ActionContext(actor: 'customer:1', approvalContext: ['tenant_id' => 'store-1']),
    );

    return new Evaluation($envelope, $capability, ['order_id' => $orderId], Decision::requireConfirmation('Confirm.'), EvaluationStage::Execution);
}

function issueGuardPermitSummaries(): void
{
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Untrusted, Trust::Trusted),
    );
}

dataset('issue-guard drivers', ['database', 'in-memory']);

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach ([verdictTable('approvals'), ISSUE_GUARD_TABLE] as $table) {
        $schema->dropIfExists($table);
    }

    foreach ([
        'create_verdict_approval_receipts_table.php.stub',
        'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        'add_approval_context_to_verdict_approval_receipts_table.php.stub',
        'create_verdict_consumed_binding_guards_table.php.stub',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach ([verdictTable('approvals'), ISSUE_GUARD_TABLE] as $table) {
        $schema->dropIfExists($table);
    }
});

it('exposes PreviouslyConsumed as a non-success outcome', function (): void {
    expect(ApprovalOutcome::PreviouslyConsumed->succeeded())->toBeFalse();
});

it('refuses issuance with PreviouslyConsumed — querying the exact triple — when a guard exists and no row does', function (string $driver, string $capability): void {
    $guards = issueGuardRecordingStore();
    $store = issueGuardStoreFor($driver, $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $fingerprint = hash('sha256', 'binding');
    $guards->remember(ConsumedBindingGuard::digest($toolCallId, $capability, $fingerprint), issueGuardTime());
    $guards->reads = [];

    $transition = $store->issue(issueGuardReceipt($toolCallId, $capability, $fingerprint));

    expect($transition->outcome)->toBe(ApprovalOutcome::PreviouslyConsumed)
        ->and($transition->succeeded())->toBeFalse()
        ->and($transition->receipt)->toBeNull()
        // it queried the FULL triple digest (a pair-only lookup would query a different value)
        ->and($guards->reads)->toContain(ConsumedBindingGuard::digest($toolCallId, $capability, $fingerprint))
        // and it minted NOTHING — a refused issuance leaves no receipt for the binding.
        ->and($store->findForToolCall($toolCallId))->toBeNull();
    // The two capabilities catch a hard-coded capability in the digest.
})->with('issue-guard drivers')->with(['orders.cancel', 'inventory.adjust']);

it('mints when the only guard is for a different tool-call, capability, or fingerprint', function (string $driver, string $which): void {
    $guards = issueGuardRecordingStore();
    $store = issueGuardStoreFor($driver, $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $capability = 'orders.cancel';
    $fingerprint = hash('sha256', 'binding');

    // Seed a guard that differs in exactly ONE component of the triple; it must not block this binding.
    [$gTool, $gCap, $gFp] = match ($which) {
        'tool-call' => ['call-'.bin2hex(random_bytes(6)), $capability, $fingerprint],
        'capability' => [$toolCallId, 'orders.refund', $fingerprint],
        'fingerprint' => [$toolCallId, $capability, hash('sha256', 'other')],
    };
    $guards->remember(ConsumedBindingGuard::digest($gTool, $gCap, $gFp), issueGuardTime());

    expect($store->issue(issueGuardReceipt($toolCallId, $capability, $fingerprint))->outcome)
        ->toBe(ApprovalOutcome::Issued);
})->with('issue-guard drivers')->with(['tool-call', 'capability', 'fingerprint']);

it('mints when no guard exists and when no guard store is configured', function (string $driver): void {
    $withEmptyGuard = issueGuardStoreFor($driver, issueGuardRecordingStore());
    expect($withEmptyGuard->issue(issueGuardReceipt('call-'.bin2hex(random_bytes(6)), 'orders.cancel', hash('sha256', 'a')))->outcome)
        ->toBe(ApprovalOutcome::Issued);

    $withoutGuardStore = $driver === 'database'
        ? new DatabaseApprovalReceiptStore(connection: app(DatabaseManager::class)->connection())
        : new InMemoryApprovalReceiptStore;
    expect($withoutGuardStore->issue(issueGuardReceipt('call-'.bin2hex(random_bytes(6)), 'orders.cancel', hash('sha256', 'b')))->outcome)
        ->toBe(ApprovalOutcome::Issued);
})->with('issue-guard drivers');

it('checks the existing row BEFORE the guard for every row state — the guard is never consulted when a row exists', function (string $driver, string $state): void {
    $guards = issueGuardRecordingStore();
    $store = issueGuardStoreFor($driver, $guards);

    $toolCallId = 'call-'.bin2hex(random_bytes(6));
    $capability = 'orders.cancel';
    $fingerprint = hash('sha256', 'binding');

    // Seed a row in the requested state, and record the seeded receipt id + the outcome today's path owes.
    [$seededId, $issueReceipt, $expected] = match ($state) {
        'pending' => (function () use ($store, $toolCallId, $capability, $fingerprint): array {
            $r = issueGuardReceipt($toolCallId, $capability, $fingerprint);
            expect($store->issue($r)->outcome)->toBe(ApprovalOutcome::Issued);

            return [$r->id, issueGuardReceipt($toolCallId, $capability, $fingerprint), ApprovalOutcome::Existing];
        })(),
        'approved' => (function () use ($store, $toolCallId, $capability, $fingerprint): array {
            $r = issueGuardReceipt($toolCallId, $capability, $fingerprint);
            $store->issue($r);
            expect($store->approve($r->id, $toolCallId, 'a', issueGuardTime())->outcome)->toBe(ApprovalOutcome::Approved);

            return [$r->id, issueGuardReceipt($toolCallId, $capability, $fingerprint), ApprovalOutcome::Existing];
        })(),
        'consumed' => (function () use ($store, $toolCallId, $capability, $fingerprint): array {
            $r = issueGuardReceipt($toolCallId, $capability, $fingerprint);
            $store->issue($r);
            $store->approve($r->id, $toolCallId, 'a', issueGuardTime());
            expect($store->consume($toolCallId, $fingerprint, issueGuardTime('2026-08-01 12:05:00'))->outcome)->toBe(ApprovalOutcome::Consumed);

            return [$r->id, issueGuardReceipt($toolCallId, $capability, $fingerprint), ApprovalOutcome::InvalidState];
        })(),
        'rejected' => (function () use ($store, $toolCallId, $capability, $fingerprint): array {
            $r = issueGuardReceipt($toolCallId, $capability, $fingerprint);
            $store->issue($r);
            expect($store->reject($r->id, $toolCallId, 'x', issueGuardTime())->outcome)->toBe(ApprovalOutcome::Rejected);

            return [$r->id, issueGuardReceipt($toolCallId, $capability, $fingerprint), ApprovalOutcome::InvalidState];
        })(),
        'expired' => (function () use ($store, $toolCallId, $capability, $fingerprint): array {
            // Seeded row expires at 11:00; the re-issue is created at 12:00, so the existing row is expired.
            $r = issueGuardReceipt($toolCallId, $capability, $fingerprint, createdAt: '2026-08-01 10:00:00', expiresIn: '+1 hour');
            expect($store->issue($r)->outcome)->toBe(ApprovalOutcome::Issued);

            return [$r->id, issueGuardReceipt($toolCallId, $capability, $fingerprint, createdAt: '2026-08-01 12:00:00'), ApprovalOutcome::Expired];
        })(),
    };

    // A MATCHING guard is present, so only the row-check-first contract keeps this from refusing.
    $guards->remember(ConsumedBindingGuard::digest($toolCallId, $capability, $fingerprint), issueGuardTime());
    $guards->reads = []; // reset AFTER all setup

    $transition = $store->issue($issueReceipt);

    expect($transition->outcome)->toBe($expected)
        ->and($transition->receipt?->id)->toBe($seededId) // the returned receipt identifies the original row
        ->and($guards->reads)->toBe([]); // the guard was NEVER consulted, because a row exists
})->with('issue-guard drivers')->with(['pending', 'approved', 'consumed', 'rejected', 'expired']);

it('maps a guard-blocked re-issuance to IssuanceRefused(PreviouslyConsumed) end to end through the manager', function (): void {
    issueGuardPermitSummaries();
    $connection = app(DatabaseManager::class)->connection();
    $store = new DatabaseApprovalReceiptStore(
        connection: $connection,
        guards: new DatabaseConsumedBindingGuardStore($connection, ISSUE_GUARD_TABLE),
    );
    $manager = issueGuardManager($store);
    $evaluation = issueGuardEvaluation(issueGuardCapability());

    // Issue -> approve -> consume writes the guard for the real binding fingerprint.
    $first = $manager->issue($evaluation);
    expect($first->outcome)->toBe(ApprovalOutcome::Issued);
    $receipt = $first->receipt;
    expect($receipt)->not->toBeNull();

    expect($manager->approve($receipt->id, $receipt->toolCallId, 'human')->outcome)->toBe(ApprovalOutcome::Approved);
    expect($store->consume($receipt->toolCallId, $receipt->bindingFingerprint, app(Clock::class)->now())->outcome)
        ->toBe(ApprovalOutcome::Consumed);

    // Simulate a prune: remove the consumed receipt row; the permanent guard remains.
    $connection->table(verdictTable('approvals'))->where('id', $receipt->id)->delete();
    expect($connection->table(verdictTable('approvals'))->count())->toBe(0);

    // Re-issuing the same binding now finds no row + a guard -> refused, surfaced as IssuanceRefused.
    $second = $manager->issue($evaluation);
    expect($second->outcome)->toBe(ApprovalOutcome::IssuanceRefused)
        ->and($second->refusalReason)->toBe(IssuanceRefusalReason::PreviouslyConsumed)
        ->and($second->receipt)->toBeNull()
        ->and($second->succeeded())->toBeFalse()
        ->and($connection->table(verdictTable('approvals'))->count())->toBe(0);
});
