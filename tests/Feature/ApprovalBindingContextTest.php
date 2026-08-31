<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Evidence\ArgumentFingerprint;

it('captures the action context approval context onto the receipt at issue time', function (): void {
    $context = new ActionContext(
        actor: 'customer:72',
        approvalContext: ['tenant_id' => 'tenant-9', 'conversation_id' => 'conv-41'],
    );

    $transition = app(ApprovalManager::class)->issue(confirmationEvaluation($context, 'call-binding-context-1'));

    expect($transition->outcome)->toBe(ApprovalOutcome::Issued);

    $receipt = app(ApprovalReceiptStore::class)->findForToolCall('call-binding-context-1')->receipt;

    expect($receipt)->not->toBeNull()
        ->and($receipt->approvalContext)->toBe(['tenant_id' => 'tenant-9', 'conversation_id' => 'conv-41']);
});

it('issues a separate receipt when the same binding arrives under a different approval context', function (): void {
    $first = new ActionContext(actor: 'customer:72', approvalContext: ['conversation_id' => 'conv-41']);
    $second = new ActionContext(actor: 'customer:72', approvalContext: ['conversation_id' => 'conv-99']);

    $issued = app(ApprovalManager::class)->issue(confirmationEvaluation($first, 'call-context-identity'));
    $reissued = app(ApprovalManager::class)->issue(confirmationEvaluation($second, 'call-context-identity'));

    // A colliding tool-call id with identical arguments but a different conversation must not
    // return Existing: that would bind the second conversation to a receipt carrying — and later
    // consuming an approval authorized against — the first conversation's context.
    expect($issued->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($reissued->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($reissued->receipt?->approvalContext)->toBe(['conversation_id' => 'conv-99'])
        ->and($reissued->receipt?->id)->not->toBe($issued->receipt?->id);
});

it('keeps the pre-capture binding fingerprint when no approval context is supplied', function (): void {
    app(ApprovalManager::class)->issue(confirmationEvaluation(new ActionContext(actor: 'customer:72'), 'call-precapture-shape'));

    // Guided upgrade: an application that has not adopted approvalContext must produce the exact
    // fingerprint 0.11 produced, or every receipt pending across composer update mismatches.
    $preCaptureShape = ArgumentFingerprint::make([
        'capability' => 'orders.cancel',
        'execution_target_policy' => null,
        'arguments' => ['order_id' => 1001],
        'binding' => ['order_id' => 1001],
    ]);

    expect(app(ApprovalReceiptStore::class)->findForToolCall('call-precapture-shape')->receipt?->bindingFingerprint)
        ->toBe($preCaptureShape);
});
