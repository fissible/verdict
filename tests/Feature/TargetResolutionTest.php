<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Exceptions\TargetNotResolvable;
use Fissible\Verdict\VerdictManager;

final class ResolutionAuthorizer implements CapabilityAuthorizer
{
    public int $calls = 0;

    public function __construct(private readonly bool $shouldThrow = false) {}

    public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
    {
        $this->calls++;

        if ($this->shouldThrow) {
            throw new RuntimeException('Policy implementation failed.');
        }

        return Decision::permit();
    }
}

function resolutionEnvelope(): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal(
            capability: 'orders.resolve',
            arguments: ['order_id' => 404],
            idempotencyKey: 'call-missing-order',
        ),
        context: new ActionContext(actor: 'customer-72'),
    );
}

function resolutionEvidenceRecorder(): InMemoryEvidenceRecorder
{
    $recorder = app(EvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        throw new LogicException('Expected the in-memory evidence recorder.');
    }

    return $recorder;
}

it('records an expected target-resolution failure as a denial without authorizing or executing', function (): void {
    $authorizer = new ResolutionAuthorizer;
    $this->app->instance(CapabilityAuthorizer::class, $authorizer);
    $executorCalls = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.resolve',
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): never => throw TargetNotResolvable::make(
            new RuntimeException('Database lookup details must not enter evidence.'),
        ),
    )->executeUsing(function (AuthorizedAction $action) use (&$executorCalls): string {
        $executorCalls++;

        return 'executed';
    }));

    $result = $verdict->runBound(resolutionEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($result->evaluation->stage)->toBe(EvaluationStage::Proposal)
        ->and($result->evaluation->target)->toBeNull()
        ->and($result->evaluation->decision->reason)->toBe(TargetNotResolvable::DECISION_REASON)
        ->and($authorizer->calls)->toBe(0)
        ->and($executorCalls)->toBe(0);

    $evidence = resolutionEvidenceRecorder()->all();

    expect($evidence)->toHaveCount(1)
        ->and($evidence[0]->disposition)->toBe('deny')
        ->and($evidence[0]->reason)->toBe(TargetNotResolvable::DECISION_REASON)
        ->and($evidence[0]->idempotencyKey)->toBe('call-missing-order');
});

it('does not disguise an unexpected resolver exception as a policy denial', function (): void {
    $authorizer = new ResolutionAuthorizer;
    $this->app->instance(CapabilityAuthorizer::class, $authorizer);
    $executorCalls = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.resolve',
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): never => throw new RuntimeException('Resolver implementation failed.'),
    )->executeUsing(function (AuthorizedAction $action) use (&$executorCalls): string {
        $executorCalls++;

        return 'executed';
    }));

    expect(fn () => $verdict->runBound(resolutionEnvelope()))
        ->toThrow(RuntimeException::class, 'Resolver implementation failed.')
        ->and($authorizer->calls)->toBe(0)
        ->and($executorCalls)->toBe(0);
});

it('does not disguise an unexpected authorizer exception as a policy denial', function (): void {
    $authorizer = new ResolutionAuthorizer(shouldThrow: true);
    $this->app->instance(CapabilityAuthorizer::class, $authorizer);
    $executorCalls = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.resolve',
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): object => (object) ['id' => 404],
    )->executeUsing(function (AuthorizedAction $action) use (&$executorCalls): string {
        $executorCalls++;

        return 'executed';
    }));

    expect(fn () => $verdict->runBound(resolutionEnvelope()))
        ->toThrow(RuntimeException::class, 'Policy implementation failed.')
        ->and($authorizer->calls)->toBe(1)
        ->and($executorCalls)->toBe(0);
});
