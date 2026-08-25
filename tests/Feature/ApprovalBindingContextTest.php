<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;

function bindingContextEvaluation(ActionContext $context, string $toolCallId = 'call-binding-context-1'): Evaluation
{
    $arguments = ['order_id' => 1001];

    $capability = Capability::usingPolicy('orders.cancel', 'update', fn (ActionEnvelope $envelope): array => $envelope->proposal->arguments)
        ->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $envelope, array $target): array => $target,
            reason: 'Confirm this cancellation.',
        );

    $envelope = ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', $arguments, $toolCallId),
        $context,
    );

    return new Evaluation($envelope, $capability, $arguments, Decision::requireConfirmation('Confirm this cancellation.'), EvaluationStage::Proposal);
}

it('captures the action context approval context onto the receipt at issue time', function (): void {
    $context = new ActionContext(
        actor: 'customer:72',
        approvalContext: ['tenant_id' => 'tenant-9', 'conversation_id' => 'conv-41'],
    );

    $transition = app(ApprovalManager::class)->issue(bindingContextEvaluation($context));

    expect($transition->outcome)->toBe(ApprovalOutcome::Issued);

    $receipt = app(ApprovalReceiptStore::class)->findForToolCall('call-binding-context-1');

    expect($receipt)->not->toBeNull()
        ->and($receipt->approvalContext)->toBe(['tenant_id' => 'tenant-9', 'conversation_id' => 'conv-41']);
});
