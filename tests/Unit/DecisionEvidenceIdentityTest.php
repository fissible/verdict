<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Contracts\ProvidesVerdictIdentity;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\DecisionEvidence;

final readonly class EvidenceIdentity implements ProvidesVerdictIdentity
{
    public function __construct(private string $value) {}

    public function verdictIdentity(): string
    {
        return $this->value;
    }
}

function decisionEvidenceForIdentityContext(ActionContext $context): DecisionEvidence
{
    return DecisionEvidence::fromEvaluation(new Evaluation(
        envelope: ActionEnvelope::wrap(new ActionProposal('orders.view'), $context),
        capability: null,
        target: null,
        decision: Decision::deny('Denied by policy.'),
        stage: EvaluationStage::Proposal,
    ));
}

it('records application-supplied actor and subject identities as fingerprints', function (): void {
    $evidence = decisionEvidenceForIdentityContext(new ActionContext(
        actor: new EvidenceIdentity('support-agent:17'),
        metadata: ['tenant' => 'acme'],
        subject: new EvidenceIdentity('customer:72'),
    ));

    expect($evidence->actorFingerprint)->toBe(hash('sha256', 'support-agent:17'))
        ->and($evidence->subjectFingerprint)->toBe(hash('sha256', 'customer:72'));
});

it('preserves positional ActionContext construction and records no inferred identity', function (): void {
    $context = new ActionContext('customer-72', ['tenant' => 'acme']);
    $evidence = decisionEvidenceForIdentityContext($context);

    expect($context->subject)->toBeNull()
        ->and($evidence->actorFingerprint)->toBeNull()
        ->and($evidence->subjectFingerprint)->toBeNull();
});

it('rejects an empty application-supplied identity', function (): void {
    decisionEvidenceForIdentityContext(new ActionContext(new EvidenceIdentity('')));
})->throws(InvalidArgumentException::class, 'Verdict identities must not be empty.');
