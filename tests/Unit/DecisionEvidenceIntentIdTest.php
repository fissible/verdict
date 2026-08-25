<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\RecordDigest;

function intentIdEvaluation(): Evaluation
{
    static $evaluation = null;

    return $evaluation ??= new Evaluation(
        envelope: ActionEnvelope::wrap(new ActionProposal('orders.refund', ['order' => 42]), new ActionContext(null)),
        capability: null,
        target: null,
        decision: Decision::permit('Permitted.'),
        stage: EvaluationStage::RateLimit,
    );
}

function intentIdEvidence(?string $intentId): DecisionEvidence
{
    return DecisionEvidence::fromEvaluation(intentIdEvaluation(), invocationId: null, intentId: $intentId);
}

it('carries the intent id an outcome record references, defaulting to none', function (): void {
    expect(intentIdEvidence('intent-1')->intentId)->toBe('intent-1')
        ->and(intentIdEvidence(null)->intentId)->toBeNull();
});

it('excludes the intent reference from the record digest', function (): void {
    // The stable field set is frozen under the digest scheme: adding a field would silently change
    // the identity of every record already published. The intent id is a correlation pointer to a
    // row in the operational layer, not part of what this record claims — like `reason`, it stays
    // outside the digest, and the limitation is documented rather than implied away (#160).
    // Both records are minted with a fresh recorded_at; re-mint the pair if the two calls
    // straddled a second boundary, so the comparison isolates the intent id alone.
    do {
        $with = intentIdEvidence('intent-1');
        $without = intentIdEvidence(null);
    } while ($with->recordedAt->format('Y-m-d H:i:s') !== $without->recordedAt->format('Y-m-d H:i:s'));

    expect($with->recordDigest)->toBe($without->recordDigest)
        ->and(RecordDigest::stableFields($with))->not->toHaveKey('intent_id');
});
