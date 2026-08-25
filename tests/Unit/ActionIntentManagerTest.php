<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\ActionIntentStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\ProvidesVerdictIdentity;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Fissible\Verdict\Intents\ActionIntent;
use Fissible\Verdict\Intents\ActionIntentManager;
use Fissible\Verdict\Intents\InMemoryActionIntentStore;

final readonly class IntentIdentity implements ProvidesVerdictIdentity
{
    public function __construct(private string $value) {}

    public function verdictIdentity(): string
    {
        return $this->value;
    }
}

final class FrozenIntentClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-25 12:00:00', new DateTimeZone('UTC'));
    }
}

final class FailingActionIntentStore implements ActionIntentStore
{
    public function record(ActionIntent $intent): void
    {
        throw new RuntimeException('The intent store is unavailable.');
    }

    public function find(string $id): ?ActionIntent
    {
        return null;
    }
}

final class UnsafeTransactionActionIntentStore implements ActionIntentStore
{
    public function record(ActionIntent $intent): void
    {
        throw UnsafeOuterTransaction::forStoreMutation('record an action intent', 1);
    }

    public function find(string $id): ?ActionIntent
    {
        return null;
    }
}

function intentManagerCapability(): Capability
{
    return Capability::usingPolicy('orders.refund', 'refund', fn () => null);
}

function intentEvaluation(Capability $capability): Evaluation
{
    return new Evaluation(
        envelope: ActionEnvelope::wrap(
            new ActionProposal('orders.refund', ['order' => 42]),
            new ActionContext(
                actor: new IntentIdentity('support-agent:17'),
                subject: new IntentIdentity('customer:72'),
            ),
        ),
        capability: $capability,
        target: null,
        decision: Decision::permit('Permitted.'),
        stage: EvaluationStage::Execution,
    );
}

it('resolves the effective requirement from the global lever and the capability override', function (
    ?bool $declared,
    bool $global,
    bool $expected,
): void {
    $manager = new ActionIntentManager(new InMemoryActionIntentStore, new FrozenIntentClock, $global);
    $capability = intentManagerCapability();

    if ($declared !== null) {
        $capability = $capability->requiresIntentRecord($declared);
    }

    expect($manager->required($capability))->toBe($expected);
})->with([
    'undeclared follows global off' => [null, false, false],
    'undeclared follows global on' => [null, true, true],
    'declared true overrides global off' => [true, false, true],
    'declared false overrides global on' => [false, true, false],
]);

it('writes a durable intent carrying the full standalone identity and permits', function (): void {
    $store = new InMemoryActionIntentStore;
    $manager = new ActionIntentManager($store, new FrozenIntentClock, true);
    $capability = intentManagerCapability();

    $admission = $manager->record(
        intentEvaluation($capability),
        executionTargetIdentityFingerprint: hash('sha256', 'target'),
        invocationId: 'invocation-1',
    );

    $intent = $admission->intent;

    expect($admission->decision->permitsExecution())->toBeTrue()
        ->and($admission->decision->metadata['intent_id'] ?? null)->toBe($intent->id)
        ->and($intent->id)->toHaveLength(64)
        ->and($intent->capability)->toBe('orders.refund')
        ->and($intent->configurationFingerprint)->toBe($capability->configurationFingerprint())
        ->and($intent->actorFingerprint)->toBe(hash('sha256', 'support-agent:17'))
        ->and($intent->subjectFingerprint)->toBe(hash('sha256', 'customer:72'))
        ->and($intent->executionTargetIdentityFingerprint)->toBe(hash('sha256', 'target'))
        ->and($intent->argumentFingerprint)->toBe(ArgumentFingerprint::make(['order' => 42]))
        ->and($intent->invocationId)->toBe('invocation-1')
        ->and($intent->recordedAt->format('Y-m-d H:i:s'))->toBe('2026-08-25 12:00:00')
        ->and($store->find($intent->id))->not->toBeNull();
});

it('records no inferred identity for positional context values', function (): void {
    $manager = new ActionIntentManager(new InMemoryActionIntentStore, new FrozenIntentClock, true);
    $capability = intentManagerCapability();

    $admission = $manager->record(
        new Evaluation(
            envelope: ActionEnvelope::wrap(
                new ActionProposal('orders.refund', ['order' => 42]),
                new ActionContext('customer-72'),
            ),
            capability: $capability,
            target: null,
            decision: Decision::permit('Permitted.'),
            stage: EvaluationStage::Execution,
        ),
        executionTargetIdentityFingerprint: null,
        invocationId: null,
    );

    expect($admission->intent->actorFingerprint)->toBeNull()
        ->and($admission->intent->subjectFingerprint)->toBeNull()
        ->and($admission->intent->executionTargetIdentityFingerprint)->toBeNull()
        ->and($admission->intent->invocationId)->toBeNull();
});

it('returns a policy-shaped denial with nothing recorded when the intent write fails', function (): void {
    $manager = new ActionIntentManager(new FailingActionIntentStore, new FrozenIntentClock, true);

    $admission = $manager->record(intentEvaluation(intentManagerCapability()), null, null);

    expect($admission->intent)->toBeNull()
        ->and($admission->decision->permitsExecution())->toBeFalse()
        ->and($admission->decision->reason)
        ->toBe('A durable intent record could not be written, and this capability must not act unrecorded.')
        ->and($admission->failureMessage)->toBe('The intent store is unavailable.');
});

it('propagates an unsafe outer transaction instead of converting it to a denial', function (): void {
    $manager = new ActionIntentManager(new UnsafeTransactionActionIntentStore, new FrozenIntentClock, true);

    expect(fn () => $manager->record(intentEvaluation(intentManagerCapability()), null, null))
        ->toThrow(UnsafeOuterTransaction::class);
});

it('refuses to record for an evaluation without a capability', function (): void {
    $manager = new ActionIntentManager(new InMemoryActionIntentStore, new FrozenIntentClock, true);
    $evaluation = new Evaluation(
        envelope: ActionEnvelope::wrap(new ActionProposal('orders.refund'), new ActionContext(null)),
        capability: null,
        target: null,
        decision: Decision::permit('Permitted.'),
        stage: EvaluationStage::Execution,
    );

    expect(fn () => $manager->record($evaluation, null, null))->toThrow(LogicException::class);
});
