<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ApprovalDecisionKind;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Approvals\ApprovedToolCalls;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EnforcesDecisionAdmissibility;
use Fissible\Verdict\Exceptions\ApprovalAuthorizerMissing;

/**
 * #436. #320 made the store's admissibility rule load-bearing for authorization: the manager stopped
 * consulting the authorizer for a receipt it reads as terminal or expired, and delegates to the store
 * trusting it to refuse. A store that does not refuse then finalizes with no authorization check —
 * and `ApprovalReceiptStore` is Stable, so a store written before #320 acquired that hole silently.
 *
 * The rule these tests pin: the manager takes #320's shortcut only for a store that has declared it
 * enforces the rule the shortcut depends on. Everything else keeps the pre-#320 order, where every
 * addressable receipt reaches the authorizer before anything is delegated.
 */
final class AdmissibilityAuthorizer implements ApprovalDecisionAuthorizer
{
    /** @var list<array{kind: ApprovalDecisionKind, status: ApprovalReceiptStatus}> */
    public array $consultations = [];

    public function __construct(public bool $allow = true) {}

    public function authorize(ApprovalReceipt $receipt, ApprovalDecisionKind $kind, string $decidedBy): bool
    {
        $this->consultations[] = ['kind' => $kind, 'status' => $receipt->status];

        return $this->allow;
    }
}

final class AdmissibilityClock implements Clock
{
    public function __construct(public DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

/**
 * Delegates everything to a real store, recording which decision methods reached it. It does NOT
 * declare EnforcesDecisionAdmissibility, which is the realistic case this issue is about: an
 * application decorating a compliant store for logging or tenancy silently drops the declaration.
 * The fallback has to be toward more authorization, never less.
 */
class RecordingAdmissibilityStore implements ApprovalReceiptStore
{
    /** @var list<string> */
    public array $decisionCalls = [];

    /** @var list<ApprovalTransition> */
    public array $decisionTransitions = [];

    public function __construct(protected readonly ApprovalReceiptStore $inner) {}

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
        $this->decisionCalls[] = 'approve';

        return $this->decisionTransitions[] = $this->inner->approve($receiptId, $toolCallId, $approvedBy, $at);
    }

    public function reject(string $receiptId, string $toolCallId, string $rejectedBy, DateTimeImmutable $at): ApprovalTransition
    {
        $this->decisionCalls[] = 'reject';

        return $this->decisionTransitions[] = $this->inner->reject($receiptId, $toolCallId, $rejectedBy, $at);
    }

    public function validate(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        return $this->inner->validate($toolCallId, $bindingFingerprint, $at);
    }

    public function consume(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        return $this->inner->consume($toolCallId, $bindingFingerprint, $at);
    }
}

/** The same decorator, declaring that it refuses an inadmissible decision. */
final class DeclaringRecordingAdmissibilityStore extends RecordingAdmissibilityStore implements EnforcesDecisionAdmissibility {}

/**
 * A deliberately lax store: it finalizes whatever it is asked to finalize, with no state check at
 * all. This is the store #436 is about — legal PHP against the Stable contract, wrong about state.
 */
class LaxReceiptStore implements ApprovalReceiptStore
{
    /** @var array<string, ApprovalReceipt> */
    public array $receipts = [];

    /** @var list<string> */
    public array $decisionCalls = [];

    public function issue(ApprovalReceipt $receipt): ApprovalTransition
    {
        $this->receipts[$receipt->id] = $receipt;

        return ApprovalTransition::to(ApprovalOutcome::Issued, $receipt);
    }

    public function findForToolCall(string $toolCallId): ?ApprovalReceipt
    {
        $matches = array_values(array_filter(
            $this->receipts,
            static fn (ApprovalReceipt $receipt): bool => $receipt->toolCallId === $toolCallId,
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function find(string $receiptId): ?ApprovalReceipt
    {
        return $this->receipts[$receiptId] ?? null;
    }

    public function approve(string $receiptId, string $toolCallId, string $approvedBy, DateTimeImmutable $at): ApprovalTransition
    {
        $this->decisionCalls[] = 'approve';

        return $this->finalize($receiptId, ApprovalReceiptStatus::Approved, $approvedBy, $at, ApprovalOutcome::Approved);
    }

    public function reject(string $receiptId, string $toolCallId, string $rejectedBy, DateTimeImmutable $at): ApprovalTransition
    {
        $this->decisionCalls[] = 'reject';

        return $this->finalize($receiptId, ApprovalReceiptStatus::Rejected, $rejectedBy, $at, ApprovalOutcome::Rejected);
    }

    /**
     * The execution gate works normally here. This fixture is lax about decision admissibility and
     * nothing else, so a Consumed receipt can be reached the way a real one is.
     */
    public function validate(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        $receipt = $this->forBinding($toolCallId, $bindingFingerprint);

        if ($receipt === null) {
            return ApprovalTransition::to(ApprovalOutcome::NotFound);
        }

        if ($receipt->isExpiredAt($at)) {
            return ApprovalTransition::to(ApprovalOutcome::Expired, $receipt);
        }

        return $receipt->status === ApprovalReceiptStatus::Approved
            ? ApprovalTransition::to(ApprovalOutcome::Approved, $receipt)
            : ApprovalTransition::to(ApprovalOutcome::InvalidState, $receipt);
    }

    public function consume(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        $validation = $this->validate($toolCallId, $bindingFingerprint, $at);

        if (! $validation->succeeded()) {
            return $validation;
        }

        /** @var ApprovalReceipt $receipt */
        $receipt = $this->forBinding($toolCallId, $bindingFingerprint);

        return $this->finalize($receipt->id, ApprovalReceiptStatus::Consumed, '', $at, ApprovalOutcome::Consumed);
    }

    private function forBinding(string $toolCallId, string $bindingFingerprint): ?ApprovalReceipt
    {
        foreach ($this->receipts as $receipt) {
            if ($receipt->toolCallId === $toolCallId && hash_equals($receipt->bindingFingerprint, $bindingFingerprint)) {
                return $receipt;
            }
        }

        return null;
    }

    private function finalize(
        string $receiptId,
        ApprovalReceiptStatus $status,
        string $decidedBy,
        DateTimeImmutable $at,
        ApprovalOutcome $outcome,
    ): ApprovalTransition {
        $receipt = $this->receipts[$receiptId] ?? null;

        if ($receipt === null) {
            return ApprovalTransition::to(ApprovalOutcome::NotFound);
        }

        // No state check whatsoever. That is the point of this fixture.
        $updated = new ApprovalReceipt(
            id: $receipt->id,
            toolCallId: $receipt->toolCallId,
            capability: $receipt->capability,
            bindingFingerprint: $receipt->bindingFingerprint,
            provenance: $receipt->provenance,
            approvalContext: $receipt->approvalContext,
            status: $status,
            reason: $receipt->reason,
            expiresAt: $receipt->expiresAt,
            approvedBy: $status === ApprovalReceiptStatus::Approved ? $decidedBy : $receipt->approvedBy,
            approvedAt: $status === ApprovalReceiptStatus::Approved ? $at : $receipt->approvedAt,
            rejectedBy: $status === ApprovalReceiptStatus::Rejected ? $decidedBy : $receipt->rejectedBy,
            rejectedAt: $status === ApprovalReceiptStatus::Rejected ? $at : $receipt->rejectedAt,
            consumedAt: $status === ApprovalReceiptStatus::Consumed ? $at : $receipt->consumedAt,
            createdAt: $receipt->createdAt,
            updatedAt: $at,
        );

        $this->receipts[$receipt->id] = $updated;

        return ApprovalTransition::to($outcome, $updated);
    }
}

/** The same lax store, wrongly declaring that it refuses an inadmissible decision. */
final class DeclaringLaxReceiptStore extends LaxReceiptStore implements EnforcesDecisionAdmissibility {}

/**
 * Installs the store and a clock the test advances, before anything resolves ApprovalManager, which
 * is scoped and captures both at construction.
 */
function admissibilityHarness(ApprovalReceiptStore $store): AdmissibilityClock
{
    $clock = new AdmissibilityClock(new DateTimeImmutable('2026-08-31 09:00:00'));

    app()->instance(Clock::class, $clock);
    app()->instance(ApprovalReceiptStore::class, $store);
    app()->forgetInstance(ApprovalManager::class);

    return $clock;
}

function admissibilityAuthorizer(bool $allow): AdmissibilityAuthorizer
{
    $authorizer = new AdmissibilityAuthorizer($allow);

    config()->set('verdict.approvals.authorizer', AdmissibilityAuthorizer::class);
    app()->instance(AdmissibilityAuthorizer::class, $authorizer);

    return $authorizer;
}

function admissibilityChallenge(string $toolCallId): ApprovalChallenge
{
    $context = new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'tenant-9']);

    app(ApprovalManager::class)->issue(confirmationEvaluation($context, $toolCallId));

    $challenge = app(ApprovalManager::class)->challengeForToolCall($toolCallId);

    expect($challenge)->not->toBeNull();

    return $challenge;
}

function admissibilityDecision(string $decision, ApprovalChallenge $challenge): ApprovalTransition
{
    return match ($decision) {
        'approve' => app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9'),
        'reject' => app(ApprovalManager::class)->reject($challenge->receiptId, $challenge->toolCallId, 'user:9'),
        default => throw new InvalidArgumentException("Unknown decision [{$decision}]."),
    };
}

/**
 * Drives $challenge into the named inadmissible state, so one dataset covers all of them, and
 * asserts it actually arrived — otherwise a helper that silently did nothing would leave every row
 * measuring a pending receipt.
 */
function admissibilityReachState(string $state, ApprovalChallenge $challenge, AdmissibilityClock $clock): void
{
    match ($state) {
        'approved' => app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:1'),
        'rejected' => app(ApprovalManager::class)->reject($challenge->receiptId, $challenge->toolCallId, 'user:1'),
        'consumed' => admissibilityConsume($challenge),
        'expired' => $clock->time = $clock->time->modify('+16 minutes'),
        default => throw new InvalidArgumentException("Unknown state [{$state}]."),
    };

    $receipt = app(ApprovalReceiptStore::class)->find($challenge->receiptId);
    $expected = match ($state) {
        'approved' => ApprovalReceiptStatus::Approved,
        'rejected' => ApprovalReceiptStatus::Rejected,
        'consumed' => ApprovalReceiptStatus::Consumed,
        'expired' => ApprovalReceiptStatus::Pending,
    };

    expect($receipt?->status)->toBe($expected, "setup state [{$state}]")
        ->and($receipt?->isExpiredAt($clock->now()))->toBe($state === 'expired', "setup expiry [{$state}]");
}

/**
 * Reaches Consumed the only way it is reachable — the execution gate — rather than by writing the
 * status directly. A decision surface cannot produce it, so a fixture that faked it would not prove
 * the manager handles a receipt the execution path really did consume.
 */
function admissibilityConsume(ApprovalChallenge $challenge): void
{
    app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:1');

    $evaluation = confirmationEvaluation(
        new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'tenant-9']),
        $challenge->toolCallId,
    );

    $consumed = app(ApprovalManager::class)->withinApprovedToolCalls(
        ApprovedToolCalls::of([$challenge->toolCallId]),
        static fn (): ApprovalTransition => app(ApprovalManager::class)->consume($evaluation),
    );

    expect($consumed->outcome)->toBe(ApprovalOutcome::Consumed);
}

function admissibilityKind(string $decision): ApprovalDecisionKind
{
    return $decision === 'approve' ? ApprovalDecisionKind::Approve : ApprovalDecisionKind::Reject;
}

function admissibilityDecorator(bool $declared): RecordingAdmissibilityStore
{
    $inner = app(InMemoryApprovalReceiptStore::class);

    return $declared
        ? new DeclaringRecordingAdmissibilityStore($inner)
        : new RecordingAdmissibilityStore($inner);
}

it('is declared by every store Verdict ships', function (string $store): void {
    // Without this the whole design inverts silently: a default install would take the legacy path,
    // every #320 assertion in ApprovalDecisionAuthorizationTest would be measuring the fallback, and
    // nothing else here would notice. Asserted on the class so the database store needs no connection.
    expect(is_a($store, EnforcesDecisionAdmissibility::class, true))->toBeTrue($store);
})->with([InMemoryApprovalReceiptStore::class, DatabaseApprovalReceiptStore::class]);

it('keeps ADR 0036 semantics for a shipped store used unwrapped', function (): void {
    $store = app(InMemoryApprovalReceiptStore::class);
    $clock = admissibilityHarness($store);
    $authorizer = admissibilityAuthorizer(allow: false);
    $challenge = admissibilityChallenge('call-shipped-unwrapped');

    $clock->time = $clock->time->modify('+16 minutes');

    $transition = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');

    // The default install, with no decorator in the way. This is the arrangement #320 shipped and
    // the one an adopter gets out of the box; it must not change.
    expect($transition->outcome)->toBe(ApprovalOutcome::Expired)
        ->and($authorizer->consultations)->toBe([]);
});

it('keeps consulting the authorizer for an inadmissible receipt when the store does not declare it refuses one', function (string $state, string $decision): void {
    $store = new RecordingAdmissibilityStore(app(InMemoryApprovalReceiptStore::class));
    $clock = admissibilityHarness($store);
    $authorizer = admissibilityAuthorizer(allow: true);
    $challenge = admissibilityChallenge("call-legacy-{$state}-{$decision}");

    admissibilityReachState($state, $challenge, $clock);

    $before = $store->find($challenge->receiptId);
    $authorizer->allow = false;
    $authorizer->consultations = [];
    $store->decisionCalls = [];

    $transition = admissibilityDecision($decision, $challenge);

    // The inner store is compliant; the decorator around it is not declared, and that is the case an
    // application hits by wrapping a shipped store for logging or tenancy. The fallback must be
    // toward more authorization, so nothing is delegated until the authorizer has spoken.
    expect($transition->outcome)->toBe(ApprovalOutcome::Unauthorized)
        ->and($authorizer->consultations)->toBe([[
            'kind' => admissibilityKind($decision),
            'status' => $before?->status,
        ]])
        ->and($store->decisionCalls)->toBe([])
        ->and($store->find($challenge->receiptId))->toEqual($before);
})->with([
    ['approved', 'approve'],
    ['approved', 'reject'],
    ['rejected', 'approve'],
    ['rejected', 'reject'],
    ['consumed', 'approve'],
    ['consumed', 'reject'],
    ['expired', 'approve'],
    ['expired', 'reject'],
]);

it('skips the authorizer for an inadmissible receipt when the store declares it refuses one', function (string $decision): void {
    $store = new DeclaringRecordingAdmissibilityStore(app(InMemoryApprovalReceiptStore::class));
    $clock = admissibilityHarness($store);
    $authorizer = admissibilityAuthorizer(allow: false);
    $challenge = admissibilityChallenge("call-declared-expired-{$decision}");

    $clock->time = $clock->time->modify('+16 minutes');

    $transition = admissibilityDecision($decision, $challenge);

    // Same receipt, same state, same authorizer as the test above. Only the declaration differs, and
    // with it ADR 0036's semantics: the store reports its own state and the authorizer is not asked.
    expect($transition->outcome)->toBe(ApprovalOutcome::Expired)
        ->and($authorizer->consultations)->toBe([])
        ->and($store->decisionCalls)->toBe([$decision])
        ->and($transition)->toBe($store->decisionTransitions[0]);
})->with(['approve', 'reject']);

it('still returns an undeclared store\'s own transition once the authorizer allows', function (string $decision): void {
    $store = new RecordingAdmissibilityStore(app(InMemoryApprovalReceiptStore::class));
    $clock = admissibilityHarness($store);
    $authorizer = admissibilityAuthorizer(allow: true);
    $challenge = admissibilityChallenge("call-legacy-permitted-{$decision}");

    $clock->time = $clock->time->modify('+16 minutes');

    $transition = admissibilityDecision($decision, $challenge);

    // A compliant store that simply has not declared must not be punished for it: once authorization
    // passes, the decision is delegated and the store's own Expired comes back, object and all.
    expect($transition->outcome)->toBe(ApprovalOutcome::Expired)
        ->and($authorizer->consultations)->toBe([[
            'kind' => admissibilityKind($decision),
            'status' => ApprovalReceiptStatus::Pending,
        ]])
        ->and($store->decisionCalls)->toBe([$decision])
        ->and($transition)->toBe($store->decisionTransitions[0]);
})->with(['approve', 'reject']);

it('stops an undeclared lax store from finalizing an inadmissible receipt', function (string $state, string $decision): void {
    $store = new LaxReceiptStore;
    $clock = admissibilityHarness($store);
    $authorizer = admissibilityAuthorizer(allow: true);
    $challenge = admissibilityChallenge("call-lax-{$state}-{$decision}");

    admissibilityReachState($state, $challenge, $clock);

    $before = $store->find($challenge->receiptId);
    $authorizer->allow = false;
    $authorizer->consultations = [];
    $store->decisionCalls = [];

    $transition = admissibilityDecision($decision, $challenge);

    // The acceptance criterion. This store finalizes anything it is handed, so the only thing that
    // can stop it is the manager refusing to hand it anything — which requires the authorizer to have
    // been consulted despite the receipt being inadmissible. The whole receipt is compared, not just
    // its status: re-approving an already-approved receipt at the same frozen instant would leave
    // both status and updatedAt identical while replacing approvedBy.
    expect($transition->outcome)->toBe(ApprovalOutcome::Unauthorized)
        ->and($authorizer->consultations)->toBe([[
            'kind' => admissibilityKind($decision),
            'status' => $before?->status,
        ]])
        ->and($store->decisionCalls)->toBe([])
        ->and($store->find($challenge->receiptId))->toEqual($before);
})->with([
    ['approved', 'approve'],
    ['approved', 'reject'],
    ['rejected', 'approve'],
    ['rejected', 'reject'],
    ['consumed', 'approve'],
    ['consumed', 'reject'],
    ['expired', 'approve'],
    ['expired', 'reject'],
]);

it('does not second-guess a store that declares enforcement, so a false declaration is the store\'s own defect', function (): void {
    $store = new DeclaringLaxReceiptStore;
    $clock = admissibilityHarness($store);
    $authorizer = admissibilityAuthorizer(allow: false);
    $challenge = admissibilityChallenge('call-lax-declared');

    $clock->time = $clock->time->modify('+16 minutes');

    $transition = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');

    // Not a supported outcome and not a compatibility promise — a demonstration of where the trust
    // boundary sits. The declaration is a claim Verdict cannot verify, so a store that declares
    // enforcement and then finalizes an expired receipt has broken its own promise, and this records
    // that the marker is a claim rather than a verification. The safe default is the undeclared path.
    expect($transition->outcome)->toBe(ApprovalOutcome::Approved)
        ->and($authorizer->consultations)->toBe([])
        ->and($store->decisionCalls)->toBe(['approve']);
});

it('consults the authorizer for a decidable receipt whatever the store declares', function (bool $declared): void {
    $store = admissibilityDecorator($declared);
    admissibilityHarness($store);
    $authorizer = admissibilityAuthorizer(allow: false);
    $challenge = admissibilityChallenge('call-decidable-'.($declared ? 'declared' : 'legacy'));

    $transition = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');

    // The control. The declaration changes nothing for a pending, unexpired receipt — both paths
    // authorize it — so the difference the other tests measure is the inadmissible case alone.
    expect($transition->outcome)->toBe(ApprovalOutcome::Unauthorized)
        ->and($authorizer->consultations)->toBe([[
            'kind' => ApprovalDecisionKind::Approve,
            'status' => ApprovalReceiptStatus::Pending,
        ]])
        ->and($store->decisionCalls)->toBe([]);
})->with([true, false]);

it('refuses without a configured authorizer whatever the store declares', function (bool $declared): void {
    $store = admissibilityDecorator($declared);
    $clock = admissibilityHarness($store);
    admissibilityAuthorizer(allow: true);
    $challenge = admissibilityChallenge('call-unconfigured-'.($declared ? 'declared' : 'legacy'));

    $clock->time = $clock->time->modify('+16 minutes');
    config()->set('verdict.approvals.authorizer', null);
    $store->decisionCalls = [];

    // Fail-closed is not a function of the store's declaration.
    expect(fn () => app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9'))
        ->toThrow(ApprovalAuthorizerMissing::class)
        ->and($store->decisionCalls)->toBe([]);
})->with([true, false]);
