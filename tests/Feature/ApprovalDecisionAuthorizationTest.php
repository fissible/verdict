<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ApprovalDecisionKind;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
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

    expect(app(ApprovalReceiptStore::class)->findForToolCall($challenge->toolCallId)->receipt?->status)
        ->toBe(ApprovalReceiptStatus::Pending);
});

it('refuses to reject when no authorizer is configured', function (): void {
    config()->set('verdict.approvals.authorizer', null);
    $challenge = authorizationChallenge();

    expect(fn () => app(ApprovalManager::class)->reject($challenge->receiptId, $challenge->toolCallId, 'user:9'))
        ->toThrow(ApprovalAuthorizerMissing::class);

    expect(app(ApprovalReceiptStore::class)->findForToolCall($challenge->toolCallId)->receipt?->status)
        ->toBe(ApprovalReceiptStatus::Pending);
});

it('returns unauthorized and leaves the receipt pending when the authorizer denies', function (): void {
    authorizerConfigured(allow: false);
    $challenge = authorizationChallenge();

    $transition = app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'user:9');

    expect($transition->outcome)->toBe(ApprovalOutcome::Unauthorized)
        ->and($transition->succeeded())->toBeFalse()
        ->and(app(ApprovalReceiptStore::class)->findForToolCall($challenge->toolCallId)->receipt?->status)
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
        ->and(app(ApprovalReceiptStore::class)->findForToolCall($challenge->toolCallId)->receipt?->status)
        ->toBe(ApprovalReceiptStatus::Pending);
});

it('still consults the authorizer when a second receipt shares the tool call id', function (): void {
    $authorizer = authorizerConfigured(allow: false);
    $challenge = authorizationChallenge('call-shared-id');

    // A colliding provider tool-call id with a different capability is legal under the
    // three-column unique key; it makes findForToolCall() report multiplicity rather than a
    // receipt (#425), which must not become a hole the authorizer falls through — decisions
    // look receipts up by id.
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
