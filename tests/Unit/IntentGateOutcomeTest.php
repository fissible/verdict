<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Decisions\ExecutionResult;
use Fissible\Verdict\Intents\IntentGateOutcome;
use Fissible\Verdict\VerdictManager;

function intentGateDenial(): ExecutionResult
{
    return ExecutionResult::denied(new Evaluation(
        envelope: ActionEnvelope::wrap(new ActionProposal('orders.refund'), new ActionContext(null)),
        capability: null,
        target: null,
        decision: Decision::deny('A durable intent record could not be written.'),
        stage: EvaluationStage::Intent,
    ));
}

it('carries either a denial or an intent id, never both', function (): void {
    // The two states are mutually exclusive by construction — a private constructor behind two
    // named factories — so a caller cannot read an intent id off a refused gate, or miss a denial
    // by reaching for the id first. That was the hazard in the shipped tri-state union (#331).
    $proceeding = IntentGateOutcome::proceed('intent-1');
    $leverOff = IntentGateOutcome::proceed(null);
    $refused = IntentGateOutcome::refused(intentGateDenial());

    expect($proceeding->denial)->toBeNull()
        ->and($proceeding->intentId)->toBe('intent-1')
        ->and($leverOff->denial)->toBeNull()
        ->and($leverOff->intentId)->toBeNull()
        ->and($refused->denial)->not->toBeNull()
        ->and($refused->intentId)->toBeNull()
        ->and((new ReflectionMethod(IntentGateOutcome::class, '__construct'))->isPrivate())->toBeTrue();
});

it('reports the intent gate through one named type, not a union', function (): void {
    // The acceptance criterion, pinned: reintroducing a union return type here would restore the
    // instanceof unpack at both call sites and the mis-unpack hazard a third caller would inherit.
    $returns = (new ReflectionMethod(VerdictManager::class, 'intentGate'))->getReturnType();

    expect($returns)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($returns->getName())->toBe(IntentGateOutcome::class)
        ->and($returns->allowsNull())->toBeFalse();
});
