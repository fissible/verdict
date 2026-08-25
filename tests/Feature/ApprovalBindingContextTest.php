<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;

it('captures the action context approval context onto the receipt at issue time', function (): void {
    $context = new ActionContext(
        actor: 'customer:72',
        approvalContext: ['tenant_id' => 'tenant-9', 'conversation_id' => 'conv-41'],
    );

    $transition = app(ApprovalManager::class)->issue(confirmationEvaluation($context, 'call-binding-context-1'));

    expect($transition->outcome)->toBe(ApprovalOutcome::Issued);

    $receipt = app(ApprovalReceiptStore::class)->findForToolCall('call-binding-context-1');

    expect($receipt)->not->toBeNull()
        ->and($receipt->approvalContext)->toBe(['tenant_id' => 'tenant-9', 'conversation_id' => 'conv-41']);
});
