<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\ActionIntentStore;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\Intents\ActionIntent;
use Fissible\Verdict\Intents\Events\ActionIntentWriteFailed;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Support\Facades\Event;

final readonly class IntentPipelineTarget
{
    public function __construct(public int $id) {}
}

final class RefusingActionIntentStore implements ActionIntentStore
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

function intentPipelineEnvelope(string $toolCallId = 'tool-call-1'): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal(
            capability: 'orders.cancel',
            arguments: ['order_id' => 7001],
            idempotencyKey: $toolCallId,
        ),
        context: new ActionContext(72, ['tenant_id' => 'store-1']),
    );
}

function intentPipelineCapability(): Capability
{
    return Capability::usingPolicy(
        name: 'orders.cancel',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): IntentPipelineTarget => new IntentPipelineTarget(
            (int) $envelope->proposal->arguments['order_id'],
        ),
    )->executionTarget(acceptTestSnapshot('intent-target-snapshot'))
        ->executeUsing(fn (AuthorizedAction $action): string => 'cancelled');
}

function intentPipelineRateLimit(): RateLimitPolicy
{
    return RateLimitPolicy::fixedWindow(
        name: 'orders-per-minute',
        limit: 1,
        windowSeconds: 60,
        keyUsing: fn (ActionEnvelope $envelope, IntentPipelineTarget $target): array => ['order_id' => $target->id],
    );
}

/** @return list<DecisionEvidence> */
function intentStageEvidence(string $stage): array
{
    $recorder = app(EvidenceWriter::class);
    assert($recorder instanceof InMemoryEvidenceRecorder);

    return array_values(array_filter(
        $recorder->all(),
        fn (DecisionEvidence $evidence): bool => $evidence->stage === $stage,
    ));
}

beforeEach(function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
});

it('leaves the pipeline untouched while the lever is off', function (): void {
    $verdict = app(VerdictManager::class);
    $verdict->capability(intentPipelineCapability()->rateLimit(intentPipelineRateLimit()));

    $result = $verdict->runBound(intentPipelineEnvelope());

    $recorder = app(EvidenceWriter::class);
    assert($recorder instanceof InMemoryEvidenceRecorder);

    expect($result->executed)->toBeTrue()
        ->and(intentStageEvidence('intent'))->toBe([])
        ->and(array_filter($recorder->all(), fn (DecisionEvidence $e): bool => $e->intentId !== null))->toBe([]);
});

it('commits the intent before the first mutating gate and mirrors it as evidence', function (): void {
    config()->set('verdict.intents.required', true);

    $verdict = app(VerdictManager::class);
    $verdict->capability(intentPipelineCapability()->rateLimit(intentPipelineRateLimit()));

    $first = $verdict->runBound(intentPipelineEnvelope());
    $second = $verdict->runBound(intentPipelineEnvelope('tool-call-2'));

    $mirrors = intentStageEvidence('intent');

    // Two runs, two intents: the second run's rate-limit denial happened AFTER its intent was
    // committed — the write precedes gate 10, so a denied action still has its durable record.
    expect($first->executed)->toBeTrue()
        ->and($second->executed)->toBeFalse()
        ->and($mirrors)->toHaveCount(2)
        ->and($mirrors[0]->disposition)->toBe('permit')
        ->and($mirrors[1]->disposition)->toBe('permit');

    $store = app(ActionIntentStore::class);

    foreach ($mirrors as $mirror) {
        expect($mirror->intentId)->not->toBeNull()
            ->and($store->find((string) $mirror->intentId))->not->toBeNull();
    }
});

it('records the execution-target identity on the intent row', function (): void {
    config()->set('verdict.intents.required', true);

    $verdict = app(VerdictManager::class);
    $verdict->capability(intentPipelineCapability());

    $verdict->runBound(intentPipelineEnvelope());

    $mirror = intentStageEvidence('intent')[0] ?? null;
    $refresh = intentStageEvidence('target_refresh')[0] ?? null;
    $intent = app(ActionIntentStore::class)->find((string) $mirror?->intentId);

    expect($intent)->not->toBeNull()
        ->and($intent->capability)->toBe('orders.cancel')
        ->and($intent->executionTargetIdentityFingerprint)->not->toBeNull()
        ->and($intent->executionTargetIdentityFingerprint)->toBe($refresh?->executionTargetIdentityFingerprint);
});

it('threads the intent id into every outcome record after the intent gate', function (): void {
    config()->set('verdict.intents.required', true);

    $verdict = app(VerdictManager::class);
    $verdict->capability(
        intentPipelineCapability()
            ->rateLimit(intentPipelineRateLimit())
            ->atMostOnce(ExecutionClaimPolicy::named(
                'cancel-order',
                fn (ActionEnvelope $envelope, IntentPipelineTarget $target): array => ['order_id' => $target->id],
            )),
    );

    $result = $verdict->runBound(intentPipelineEnvelope());
    $intentId = intentStageEvidence('intent')[0]->intentId;

    expect($result->executed)->toBeTrue()->and($intentId)->not->toBeNull();

    foreach (['rate_limit', 'execution_claim'] as $stage) {
        foreach (intentStageEvidence($stage) as $outcome) {
            expect($outcome->intentId)->toBe($intentId, "stage {$stage} must reference the intent");
        }
    }

    // Records written before the intent gate carry no reference — there was nothing to reference.
    foreach (['proposal', 'target_refresh', 'execution'] as $stage) {
        foreach (intentStageEvidence($stage) as $early) {
            expect($early->intentId)->toBeNull("stage {$stage} precedes the intent gate");
        }
    }
});

it('denies with nothing consumed when the intent write fails', function (): void {
    Event::fake([ActionIntentWriteFailed::class]);
    config()->set('verdict.intents.required', true);
    config()->set('verdict.intents.store', RefusingActionIntentStore::class);
    $this->app->forgetInstance(ActionIntentStore::class);
    $this->app->forgetScopedInstances();

    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(
        Capability::usingPolicy(
            name: 'orders.cancel',
            ability: 'cancel',
            resolveTarget: fn (ActionEnvelope $envelope): IntentPipelineTarget => new IntentPipelineTarget(7001),
        )->executionTarget(acceptTestSnapshot('intent-target-snapshot'))
            ->executeUsing(function (AuthorizedAction $action) use (&$executed): string {
                $executed = true;

                return 'cancelled';
            })
            ->rateLimit(intentPipelineRateLimit()),
    );

    $denied = $verdict->runBound(intentPipelineEnvelope());

    expect($denied->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($denied->evaluation->decision->reason)
        ->toBe('A durable intent record could not be written, and this capability must not act unrecorded.')
        ->and($denied->evaluation->stage->value)->toBe('intent');

    Event::assertDispatched(ActionIntentWriteFailed::class);

    // The denial evidence is recorded, refused, before any mutation.
    $refusals = intentStageEvidence('intent');
    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]->disposition)->toBe('deny')
        ->and(intentStageEvidence('rate_limit'))->toBe([]);

});

/** @verdict-claim limitation.intent-pre-mutation-only */
it('keeps the intent mirror fail-open: a mirror evidence failure never stops the action', function (): void {
    Event::fake([EvidenceWriteFailed::class]);
    config()->set('verdict.intents.required', true);
    app()->instance(EvidenceWriter::class, new class implements EvidenceWriter
    {
        public function record(DecisionEvidence $evidence): void
        {
            if ($evidence->stage === 'intent') {
                throw new RuntimeException('The evidence store is unavailable.');
            }
        }

        public function recordRelease(ContextReleaseEvidence $evidence): void {}

        public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void {}

        public function recordProvenance(ProvenanceEntry $entry): void {}

        public function recordDerivation(ProvenanceDerivation $derivation): void {}
    });

    $verdict = app(VerdictManager::class);
    $verdict->capability(intentPipelineCapability());

    $result = $verdict->runBound(intentPipelineEnvelope());

    expect($result->executed)->toBeTrue();
    Event::assertDispatched(EvidenceWriteFailed::class);
});

it('writes the intent on the unbound run path too', function (): void {
    config()->set('verdict.intents.required', true);

    $verdict = app(VerdictManager::class);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.cancel',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): IntentPipelineTarget => new IntentPipelineTarget(7001),
    ));

    $result = $verdict->run(intentPipelineEnvelope(), fn (): string => 'done');

    $mirror = intentStageEvidence('intent')[0] ?? null;
    $intent = app(ActionIntentStore::class)->find((string) $mirror?->intentId);

    expect($result->executed)->toBeTrue()
        ->and($intent)->not->toBeNull()
        // The unbound path has no execution-target refresh, so the intent records none.
        ->and($intent->executionTargetIdentityFingerprint)->toBeNull();
});

it('honors a capability opt-in while the global lever is off', function (): void {
    $verdict = app(VerdictManager::class);
    $verdict->capability(intentPipelineCapability()->requiresIntentRecord());

    $result = $verdict->runBound(intentPipelineEnvelope());

    expect($result->executed)->toBeTrue()
        ->and(intentStageEvidence('intent'))->toHaveCount(1);
});

it('honors a capability opt-out while the global lever is on', function (): void {
    config()->set('verdict.intents.required', true);

    $verdict = app(VerdictManager::class);
    $verdict->capability(intentPipelineCapability()->requiresIntentRecord(false));

    $result = $verdict->runBound(intentPipelineEnvelope());

    expect($result->executed)->toBeTrue()
        ->and(intentStageEvidence('intent'))->toBe([]);
});

it('adds no identity resolution to the lever-off path, and none for the intent row when on', function (): void {
    // Review round, findings 2 and 5: the intent row reuses the execution-target fingerprint
    // gate 7 actually validated (from the target-refresh decision), so gate 9.5 makes no third
    // call into the application's identity resolver — with the lever off OR on.
    $identityCalls = 0;

    $capability = Capability::usingPolicy(
        name: 'orders.cancel',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): IntentPipelineTarget => new IntentPipelineTarget(7001),
    )->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
        name: 'counting-snapshot',
        identityUsing: function (ActionEnvelope $envelope, IntentPipelineTarget $target) use (&$identityCalls): array {
            $identityCalls++;

            return ['id' => $target->id];
        },
    ))->executeUsing(fn (AuthorizedAction $action): string => 'cancelled');

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    $verdict->runBound(intentPipelineEnvelope());
    // Gate 5 (proposal fingerprint) and gate 7 (execution fingerprint): exactly two.
    expect($identityCalls)->toBe(2);
});

it('records on the intent row the fingerprint gate 7 validated, not a recomputation', function (): void {
    config()->set('verdict.intents.required', true);

    // A non-pure identity resolver: gate 5 and gate 7 agree, any later call diverges. The intent
    // row must carry the value the pipeline checked, and the resolver must not be consulted again.
    $identityCalls = 0;

    $capability = Capability::usingPolicy(
        name: 'orders.cancel',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): IntentPipelineTarget => new IntentPipelineTarget(7001),
    )->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
        name: 'impure-snapshot',
        identityUsing: function (ActionEnvelope $envelope, IntentPipelineTarget $target) use (&$identityCalls): array {
            $identityCalls++;

            return $identityCalls <= 2 ? ['id' => $target->id] : ['id' => 'diverged'];
        },
    ))->executeUsing(fn (AuthorizedAction $action): string => 'cancelled');

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    $result = $verdict->runBound(intentPipelineEnvelope());

    $mirror = intentStageEvidence('intent')[0] ?? null;
    $refresh = intentStageEvidence('target_refresh')[0] ?? null;
    $intent = app(ActionIntentStore::class)->find((string) $mirror?->intentId);

    expect($result->executed)->toBeTrue()
        ->and($identityCalls)->toBe(2)
        ->and($intent->executionTargetIdentityFingerprint)->toBe($refresh?->executionTargetIdentityFingerprint);
});

it('concludes a claim-less intent-gated run so a healthy success is never a verification gap', function (): void {
    // Review round, finding 1: with no rate-limit, confirmation, or claim policy, nothing after
    // the intent gate wrote evidence — so the documented scheduled-verification query flagged
    // every healthy success forever. A claim-less intent-gated run now records
    // verdict.intent.concluded around a successful executor return, referencing the intent.
    config()->set('verdict.intents.required', true);

    $verdict = app(VerdictManager::class);
    $verdict->capability(intentPipelineCapability());

    $result = $verdict->runBound(intentPipelineEnvelope());

    $mirror = intentStageEvidence('intent')[0] ?? null;
    $concluded = intentStageEvidence('intent_concluded');

    expect($result->executed)->toBeTrue()
        ->and($concluded)->toHaveCount(1)
        ->and($concluded[0]->disposition)->toBe('permit')
        ->and($concluded[0]->intentId)->toBe($mirror?->intentId)
        ->and($concluded[0]->claimType?->value)->toBe('verdict.intent.concluded');
});

it('does not conclude when a claim policy exists: claim finalization is the account', function (): void {
    config()->set('verdict.intents.required', true);

    $verdict = app(VerdictManager::class);
    $verdict->capability(intentPipelineCapability()->atMostOnce(ExecutionClaimPolicy::named(
        'cancel-order',
        fn (ActionEnvelope $envelope, IntentPipelineTarget $target): array => ['order_id' => $target->id],
    )));

    $result = $verdict->runBound(intentPipelineEnvelope());

    expect($result->executed)->toBeTrue()
        ->and(intentStageEvidence('intent_concluded'))->toBe([])
        ->and(intentStageEvidence('execution_claim'))->not->toBe([]);
});

it('leaves a claim-less executor failure unconcluded: the flagged gap is the lever working', function (): void {
    config()->set('verdict.intents.required', true);

    $verdict = app(VerdictManager::class);
    $verdict->capability(
        Capability::usingPolicy(
            name: 'orders.cancel',
            ability: 'cancel',
            resolveTarget: fn (ActionEnvelope $envelope): IntentPipelineTarget => new IntentPipelineTarget(7001),
        )->executionTarget(acceptTestSnapshot('intent-target-snapshot'))
            ->executeUsing(function (AuthorizedAction $action): string {
                throw new RuntimeException('The executor failed.');
            }),
    );

    expect(fn () => $verdict->runBound(intentPipelineEnvelope()))->toThrow(RuntimeException::class)
        ->and(intentStageEvidence('intent'))->toHaveCount(1)
        ->and(intentStageEvidence('intent_concluded'))->toBe([]);
});

it('never concludes while the lever is off', function (): void {
    $verdict = app(VerdictManager::class);
    $verdict->capability(intentPipelineCapability());

    $result = $verdict->runBound(intentPipelineEnvelope());

    expect($result->executed)->toBeTrue()
        ->and(intentStageEvidence('intent_concluded'))->toBe([]);
});

it('confines the intent reference to the documented outcome stages plus the intent records themselves', function (): void {
    // Review round, finding 9: the scheduled-verification query defines an outcome negatively
    // (stage <> 'intent'). This pins the positive set — the stages that may carry intent_id —
    // so a future record type that threads the reference without being an outcome fails here
    // and forces the query documentation to move with it.
    config()->set('verdict.intents.required', true);

    $verdict = app(VerdictManager::class);
    $verdict->capability(
        intentPipelineCapability()
            ->rateLimit(intentPipelineRateLimit())
            ->atMostOnce(ExecutionClaimPolicy::named(
                'cancel-order',
                fn (ActionEnvelope $envelope, IntentPipelineTarget $target): array => ['order_id' => $target->id],
            )),
    );
    $verdict->capability(
        Capability::usingPolicy(
            name: 'orders.note',
            ability: 'note',
            resolveTarget: fn (ActionEnvelope $envelope): IntentPipelineTarget => new IntentPipelineTarget(1),
        )->executionTarget(acceptTestSnapshot('note-snapshot'))
            ->executeUsing(fn (AuthorizedAction $action): string => 'noted'),
    );

    $verdict->runBound(intentPipelineEnvelope());
    $verdict->runBound(ActionEnvelope::wrap(
        proposal: new ActionProposal('orders.note', ['note' => 'n']),
        context: new ActionContext(72),
    ));

    $recorder = app(EvidenceWriter::class);
    assert($recorder instanceof InMemoryEvidenceRecorder);

    $stagesCarryingIntent = array_values(array_unique(array_map(
        fn (DecisionEvidence $e): string => $e->stage,
        array_filter($recorder->all(), fn (DecisionEvidence $e): bool => $e->intentId !== null),
    )));
    sort($stagesCarryingIntent);

    expect($stagesCarryingIntent)->toBe(['execution_claim', 'intent', 'intent_concluded', 'rate_limit']);
});
