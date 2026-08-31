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
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EnforcesDecisionAdmissibility;
use Fissible\Verdict\Exceptions\ApprovalAuthorizerMissing;

final class RecordingApprovalAuthorizer implements ApprovalDecisionAuthorizer
{
    /** @var list<array{receipt: ApprovalReceipt, kind: ApprovalDecisionKind, decidedBy: string}> */
    public array $decisions = [];

    public function __construct(public bool $allow = true) {}

    public function authorize(ApprovalReceipt $receipt, ApprovalDecisionKind $kind, string $decidedBy): bool
    {
        $this->decisions[] = ['receipt' => $receipt, 'kind' => $kind, 'decidedBy' => $decidedBy];

        return $this->allow;
    }
}

final class AuthorizationOrderClock implements Clock
{
    public function __construct(public DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

/**
 * Records what reaches the store and every transition it hands back. The outcome alone cannot tell
 * a manager that defers to the store from one that computed Expired/InvalidState itself — both
 * return the same enum — and calling the store proves only that it was asked, not that its answer
 * was the one returned. The recorded transitions are compared by identity for that.
 *
 * It declares EnforcesDecisionAdmissibility (#436) because it is a transparent recorder over a store
 * that enforces it. Without the declaration these tests would measure the undeclared fallback path
 * rather than the behaviour #320 decided, which is precisely the confusion #436 exists to prevent.
 */
final class DecisionRecordingReceiptStore implements ApprovalReceiptStore, EnforcesDecisionAdmissibility
{
    /** @var list<string> */
    public array $decisionCalls = [];

    /** @var list<ApprovalTransition> */
    public array $decisionTransitions = [];

    public function __construct(private readonly ApprovalReceiptStore $inner) {}

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

    public function approve(
        string $receiptId,
        string $toolCallId,
        string $approvedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        $this->decisionCalls[] = 'approve';

        return $this->decisionTransitions[] = $this->inner->approve($receiptId, $toolCallId, $approvedBy, $at);
    }

    public function reject(
        string $receiptId,
        string $toolCallId,
        string $rejectedBy,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        $this->decisionCalls[] = 'reject';

        return $this->decisionTransitions[] = $this->inner->reject($receiptId, $toolCallId, $rejectedBy, $at);
    }

    public function validate(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        return $this->inner->validate($toolCallId, $bindingFingerprint, $at);
    }

    public function consume(
        string $toolCallId,
        string $bindingFingerprint,
        DateTimeImmutable $at,
    ): ApprovalTransition {
        return $this->inner->consume($toolCallId, $bindingFingerprint, $at);
    }
}

function authorizationChallenge(string $toolCallId = 'call-authorization-1'): ApprovalChallenge
{
    $context = new ActionContext(
        actor: 'customer:72',
        approvalContext: ['tenant_id' => 'tenant-9', 'conversation_id' => 'conv-41'],
    );

    app(ApprovalManager::class)->issue(confirmationEvaluation($context, $toolCallId));

    $challenge = app(ApprovalManager::class)->challengeForToolCall($toolCallId);

    expect($challenge)->not->toBeNull();

    return $challenge;
}

/**
 * A clock the test advances, plus a store that records what reaches it. Both are installed before
 * anything resolves ApprovalManager, which is scoped and captures the clock and the store at
 * construction. Expiry is the only way to reach a terminal receipt without also changing its
 * status, so ordering is observable only against a clock a test owns.
 *
 * @return array{AuthorizationOrderClock, DecisionRecordingReceiptStore}
 */
function authorizationOrderHarness(): array
{
    $clock = new AuthorizationOrderClock(new DateTimeImmutable('2026-08-30 09:00:00'));
    $store = new DecisionRecordingReceiptStore(app(InMemoryApprovalReceiptStore::class));

    app()->instance(Clock::class, $clock);
    app()->instance(ApprovalReceiptStore::class, $store);
    app()->forgetInstance(ApprovalManager::class);

    return [$clock, $store];
}

/** Decides the named way, so one test body covers both decision methods against the same case. */
function decideAuthorization(string $decision, string $receiptId, string $toolCallId): ApprovalTransition
{
    return match ($decision) {
        'approve' => app(ApprovalManager::class)->approve($receiptId, $toolCallId, 'user:9'),
        'reject' => app(ApprovalManager::class)->reject($receiptId, $toolCallId, 'user:9'),
        default => throw new InvalidArgumentException("Unknown decision [{$decision}]."),
    };
}

function authorizerConfigured(bool $allow = true): RecordingApprovalAuthorizer
{
    $authorizer = new RecordingApprovalAuthorizer($allow);

    config()->set('verdict.approvals.authorizer', RecordingApprovalAuthorizer::class);
    app()->instance(RecordingApprovalAuthorizer::class, $authorizer);

    return $authorizer;
}

it('refuses to approve when no authorizer is configured', function (): void {
    config()->set('verdict.approvals.authorizer', null);
    $challenge = authorizationChallenge();

    expect(fn () => app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9'))
        ->toThrow(ApprovalAuthorizerMissing::class);

    expect(app(ApprovalReceiptStore::class)->findForToolCall($challenge->toolCallId)?->status)
        ->toBe(ApprovalReceiptStatus::Pending);
});

it('refuses to reject when no authorizer is configured', function (): void {
    config()->set('verdict.approvals.authorizer', null);
    $challenge = authorizationChallenge();

    expect(fn () => app(ApprovalManager::class)->reject($challenge->receiptId, $challenge->toolCallId, 'user:9'))
        ->toThrow(ApprovalAuthorizerMissing::class);

    expect(app(ApprovalReceiptStore::class)->findForToolCall($challenge->toolCallId)?->status)
        ->toBe(ApprovalReceiptStatus::Pending);
});

it('returns unauthorized and leaves the receipt pending when the authorizer denies', function (): void {
    authorizerConfigured(allow: false);
    $challenge = authorizationChallenge();

    $transition = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');

    expect($transition->outcome)->toBe(ApprovalOutcome::Unauthorized)
        ->and($transition->succeeded())->toBeFalse()
        ->and(app(ApprovalReceiptStore::class)->findForToolCall($challenge->toolCallId)?->status)
        ->toBe(ApprovalReceiptStatus::Pending);
});

it('approves when the authorizer allows, handing it the receipt, kind, and decision maker', function (): void {
    $authorizer = authorizerConfigured(allow: true);
    $challenge = authorizationChallenge();

    $transition = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');

    expect($transition->outcome)->toBe(ApprovalOutcome::Approved)
        ->and($authorizer->decisions)->toHaveCount(1)
        ->and($authorizer->decisions[0]['receipt']->approvalContext)
        ->toBe(['tenant_id' => 'tenant-9', 'conversation_id' => 'conv-41'])
        ->and($authorizer->decisions[0]['kind'])->toBe(ApprovalDecisionKind::Approve)
        ->and($authorizer->decisions[0]['decidedBy'])->toBe('user:9');
});

it('rejects through the authorizer with the reject decision kind', function (): void {
    $authorizer = authorizerConfigured(allow: true);
    $challenge = authorizationChallenge();

    $transition = app(ApprovalManager::class)->reject($challenge->receiptId, $challenge->toolCallId, 'user:9');

    expect($transition->outcome)->toBe(ApprovalOutcome::Rejected)
        ->and($authorizer->decisions[0]['kind'])->toBe(ApprovalDecisionKind::Reject);
});

it('does not consult the authorizer for an unknown tool call', function (): void {
    $authorizer = authorizerConfigured(allow: true);

    $transition = app(ApprovalManager::class)->approve('receipt-that-never-was', 'call-that-never-was', 'user:9');

    expect($transition->outcome)->toBe(ApprovalOutcome::NotFound)
        ->and($authorizer->decisions)->toBeEmpty();
});

it('does not consult the authorizer when the receipt id does not match the tool call', function (): void {
    $authorizer = authorizerConfigured(allow: true);
    $challenge = authorizationChallenge();

    $transition = app(ApprovalManager::class)->approve('some-other-receipt-id', $challenge->toolCallId, 'user:9');

    // The store is the single authority on the outcome; decisions look up by receipt id, so a
    // wrong id is canonically NotFound (Mismatch is reserved for binding mismatches).
    expect($transition->outcome)->toBe(ApprovalOutcome::NotFound)
        ->and($authorizer->decisions)->toBeEmpty()
        ->and(app(ApprovalReceiptStore::class)->findForToolCall($challenge->toolCallId)?->status)
        ->toBe(ApprovalReceiptStatus::Pending);
});

it('still consults the authorizer when a second receipt shares the tool call id', function (): void {
    $authorizer = authorizerConfigured(allow: false);
    $challenge = authorizationChallenge('call-shared-id');

    // A colliding provider tool-call id with a different capability is legal under the
    // three-column unique key; it makes findForToolCall() ambiguous (null), which must not
    // become a hole the authorizer falls through — decisions look receipts up by id.
    $context = new ActionContext(actor: 'customer:73', approvalContext: ['conversation_id' => 'conv-99']);
    app(ApprovalManager::class)->issue(confirmationEvaluation($context, 'call-shared-id', 'orders.refund'));

    $transition = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');

    expect($transition->outcome)->toBe(ApprovalOutcome::Unauthorized)
        ->and($authorizer->decisions)->toHaveCount(1);
});

it('does not let a misconfigured authorizer class break paths that never decide a receipt', function (): void {
    config()->set('verdict.approvals.authorizer', 'App\\Nope\\MissingAuthorizer');

    $issued = app(ApprovalManager::class)->issue(confirmationEvaluation(
        new ActionContext(actor: 'customer:72'),
        'call-misconfigured-authorizer',
    ));

    // The authorizer is resolved at decision time, not manager construction: a typo'd class
    // must not take down issue()/validate()/consume(), which never consult it.
    expect($issued->outcome)->toBe(ApprovalOutcome::Issued);

    $challenge = app(ApprovalManager::class)->challengeForToolCall('call-misconfigured-authorizer');

    expect(fn () => app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9'))
        ->toThrow(LogicException::class, 'verdict.approvals.authorizer');
});

it('consults the authorizer while the receipt is pending and defers to the store once it has expired', function (): void {
    [$clock, $store] = authorizationOrderHarness();
    $authorizer = authorizerConfigured(allow: false);
    $challenge = authorizationChallenge('call-authorization-expiry');

    $denied = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');

    // While the receipt is decidable the authorizer runs, and its denial short-circuits before the
    // store: nothing reaches approve(). Advancing the clock is the only change between here and
    // the decisions below, so what differs there is the receipt's decidability and nothing else.
    expect($denied->outcome)->toBe(ApprovalOutcome::Unauthorized)
        ->and($authorizer->decisions)->toHaveCount(1)
        ->and($store->decisionCalls)->toBe([]);

    $clock->time = $clock->time->modify('+16 minutes');

    $approve = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');
    $reject = app(ApprovalManager::class)->reject($challenge->receiptId, $challenge->toolCallId, 'user:9');

    expect($approve->outcome)->toBe(ApprovalOutcome::Expired)
        ->and($reject->outcome)->toBe(ApprovalOutcome::Expired)
        ->and($authorizer->decisions)->toHaveCount(1)
        ->and($store->decisionCalls)->toBe(['approve', 'reject'])
        // Not merely the same outcome: the store's own transitions, returned unaltered.
        ->and($approve)->toBe($store->decisionTransitions[0])
        ->and($reject)->toBe($store->decisionTransitions[1])
        ->and($store->find($challenge->receiptId)?->status)->toBe(ApprovalReceiptStatus::Pending);
});

it('stops consulting the authorizer once the receipt is no longer pending', function (string $decision): void {
    [, $store] = authorizationOrderHarness();
    $authorizer = authorizerConfigured(allow: true);
    $challenge = authorizationChallenge("call-authorization-{$decision}d");

    $decided = decideAuthorization($decision, $challenge->receiptId, $challenge->toolCallId);

    expect($decided->succeeded())->toBeTrue()
        ->and($authorizer->decisions)->toHaveCount(1)
        ->and($decided)->toBe($store->decisionTransitions[0]);

    $authorizer->allow = false;
    $store->decisionCalls = [];
    $store->decisionTransitions = [];

    // The receipt is terminal but not expired, so nothing but its status makes it undecidable.
    $again = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');
    $rejected = app(ApprovalManager::class)->reject($challenge->receiptId, $challenge->toolCallId, 'user:9');

    expect($again->outcome)->toBe(ApprovalOutcome::InvalidState)
        ->and($rejected->outcome)->toBe(ApprovalOutcome::InvalidState)
        ->and($authorizer->decisions)->toHaveCount(1)
        ->and($store->decisionCalls)->toBe(['approve', 'reject'])
        ->and($again)->toBe($store->decisionTransitions[0])
        ->and($rejected)->toBe($store->decisionTransitions[1]);
})->with(['approve', 'reject']);

it('leaves expiry precedence to the store for a receipt that is both decided and expired', function (): void {
    [$clock, $store] = authorizationOrderHarness();
    $authorizer = authorizerConfigured(allow: true);
    $challenge = authorizationChallenge('call-authorization-precedence');

    app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');

    $clock->time = $clock->time->modify('+16 minutes');
    $authorizer->allow = false;
    $store->decisionCalls = [];
    $store->decisionTransitions = [];

    $transition = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');

    // The store reports expiry before status. A receipt that is both decided and expired reads
    // Expired only while the outcome is still the store's to produce; a manager that ranked the
    // two itself would have to reproduce that precedence to agree, and would own it thereafter.
    expect($transition->outcome)->toBe(ApprovalOutcome::Expired)
        ->and($authorizer->decisions)->toHaveCount(1)
        ->and($store->decisionCalls)->toBe(['approve'])
        ->and($transition)->toBe($store->decisionTransitions[0]);
});

it('refuses a decision on any receipt state when no authorizer is configured', function (): void {
    [$clock, $store] = authorizationOrderHarness();
    authorizerConfigured(allow: true);

    $expired = authorizationChallenge('call-unconfigured-expired');
    $approved = authorizationChallenge('call-unconfigured-approved');
    $rejected = authorizationChallenge('call-unconfigured-rejected');

    decideAuthorization('approve', $approved->receiptId, $approved->toolCallId);
    decideAuthorization('reject', $rejected->receiptId, $rejected->toolCallId);

    $clock->time = $clock->time->modify('+16 minutes');
    config()->set('verdict.approvals.authorizer', null);
    $store->decisionCalls = [];

    // Fail-closed outranks state reporting: resolving receipt state before authorization must not
    // turn a terminal or expired receipt into a path that answers without an authorizer. Reading
    // the receipt first is allowed — deciding one is not.
    foreach ([$expired, $approved, $rejected] as $challenge) {
        expect(fn () => app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9'))
            ->toThrow(ApprovalAuthorizerMissing::class)
            ->and(fn () => app(ApprovalManager::class)->reject($challenge->receiptId, $challenge->toolCallId, 'user:9'))
            ->toThrow(ApprovalAuthorizerMissing::class);
    }

    expect($store->decisionCalls)->toBe([]);
});

it('does not decide when the authorizer denies a rejection', function (): void {
    [, $store] = authorizationOrderHarness();
    $authorizer = authorizerConfigured(allow: false);
    $challenge = authorizationChallenge('call-authorization-denied-rejection');

    $transition = app(ApprovalManager::class)->reject($challenge->receiptId, $challenge->toolCallId, 'user:9');

    // A denial that is ignored on the reject path would still read as a refusal to the caller,
    // because the store's Rejected outcome is also a terminal one. Only the store call tells them
    // apart. The authorizer is handed the store's own receipt, not a reconstruction of it.
    expect($transition->outcome)->toBe(ApprovalOutcome::Unauthorized)
        ->and($store->decisionCalls)->toBe([])
        ->and($authorizer->decisions)->toHaveCount(1)
        ->and($authorizer->decisions[0]['receipt'])->toBe($store->find($challenge->receiptId))
        ->and($store->find($challenge->receiptId)?->status)->toBe(ApprovalReceiptStatus::Pending);
});

it('returns the store\'s own transition for a receipt the decision cannot address', function (string $decision): void {
    [, $store] = authorizationOrderHarness();
    $authorizer = authorizerConfigured(allow: true);
    $challenge = authorizationChallenge("call-unaddressable-{$decision}");

    $unknown = decideAuthorization($decision, 'receipt-that-never-was', $challenge->toolCallId);
    $mismatched = decideAuthorization($decision, $challenge->receiptId, 'call-that-never-was');

    // NotFound and Mismatch are the store's to report for the same reason Expired and InvalidState
    // are: the manager delegates rather than synthesizing an outcome it inferred from a read.
    expect($unknown->outcome)->toBe(ApprovalOutcome::NotFound)
        ->and($mismatched->outcome)->toBe(ApprovalOutcome::Mismatch)
        ->and($authorizer->decisions)->toBeEmpty()
        ->and($store->decisionCalls)->toBe([$decision, $decision])
        ->and($unknown)->toBe($store->decisionTransitions[0])
        ->and($mismatched)->toBe($store->decisionTransitions[1]);
})->with(['approve', 'reject']);
