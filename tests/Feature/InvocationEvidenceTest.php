<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Facades\Verdict;

function invocationEvidenceEnvelope(string $capability): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal($capability, []),
        new ActionContext('customer-72'),
    );
}

it('records no invocation ID for direct run and runBound calls outside Laravel AI', function (): void {
    Verdict::run(
        invocationEvidenceEnvelope('orders.direct-run'),
        fn (): never => throw new LogicException('The unregistered capability must not execute.'),
    );
    Verdict::runBound(invocationEvidenceEnvelope('orders.direct-run-bound'));

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    expect($recorder->all())->toHaveCount(2)
        ->and(array_column($recorder->all(), 'invocationId'))->each->toBeNull();
});
