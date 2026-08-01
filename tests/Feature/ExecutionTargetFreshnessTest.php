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
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Exceptions\TargetNotResolvable;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;

final class FreshnessTarget
{
    public function __construct(
        public int $id,
        public int $ownerId,
        public int $version,
    ) {}
}

final class RecordingFreshnessAuthorizer implements CapabilityAuthorizer
{
    /** @var list<int> */
    public array $versions = [];

    public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
    {
        if (! $target instanceof FreshnessTarget) {
            return Decision::deny('Unexpected target.');
        }

        $this->versions[] = $target->version;

        return $target->ownerId === $envelope->context->actor
            ? Decision::permit()
            : Decision::deny('Target authority changed.');
    }
}

function freshnessEnvelope(): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal(
            capability: 'orders.fresh-action',
            arguments: ['order_id' => 1001],
            idempotencyKey: 'freshness-call-1',
        ),
        context: new ActionContext(actor: 72, metadata: ['tenant_id' => 'store-1']),
    );
}

function freshnessIdentityPolicy(callable $refreshUsing): ExecutionTargetPolicy
{
    return ExecutionTargetPolicy::refresh(
        name: 'order-primary-key',
        identityUsing: fn (ActionEnvelope $envelope, FreshnessTarget $target): array => [
            'tenant_id' => $envelope->context->metadata['tenant_id'],
            'resource_type' => 'order',
            'resource_id' => $target->id,
        ],
        refreshUsing: $refreshUsing,
    );
}

function freshnessEvidence(): InMemoryEvidenceRecorder
{
    $evidence = app(EvidenceRecorder::class);

    if (! $evidence instanceof InMemoryEvidenceRecorder) {
        throw new LogicException('Expected in-memory evidence.');
    }

    return $evidence;
}

it('uses one refreshed target for authorization, semantic bindings, claims, and execution', function (): void {
    $proposalTarget = new FreshnessTarget(1001, 72, 1);
    $executionTarget = new FreshnessTarget(1001, 72, 2);
    $rateVersion = null;
    $claimVersion = null;
    $executedTarget = null;
    $authorizer = new RecordingFreshnessAuthorizer;
    $this->app->instance(CapabilityAuthorizer::class, $authorizer);
    $capability = Capability::usingPolicy(
        name: 'orders.fresh-action',
        ability: 'update',
        resolveTarget: fn (): FreshnessTarget => $proposalTarget,
    )->executionTarget(freshnessIdentityPolicy(
        fn (ActionEnvelope $envelope, FreshnessTarget $target): FreshnessTarget => $executionTarget,
    ))->rateLimit(RateLimitPolicy::fixedWindow(
        name: 'current-order-version',
        limit: 1,
        windowSeconds: 60,
        keyUsing: function (ActionEnvelope $envelope, FreshnessTarget $target) use (&$rateVersion): array {
            $rateVersion = $target->version;

            return ['order_id' => $target->id, 'version' => $target->version];
        },
    ))->atMostOnce(ExecutionClaimPolicy::named(
        name: 'current-order-version',
        keyUsing: function (ActionEnvelope $envelope, FreshnessTarget $target) use (&$claimVersion): array {
            $claimVersion = $target->version;

            return ['order_id' => $target->id, 'version' => $target->version];
        },
    ))->executeUsing(function (AuthorizedAction $action) use (&$executedTarget): string {
        $executedTarget = $action->target;

        return 'executed';
    });
    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    $result = $verdict->runBound(freshnessEnvelope());
    $refreshEvidence = collect(freshnessEvidence()->all())->firstWhere('stage', 'target_refresh');

    expect($result->executed)->toBeTrue()
        ->and($authorizer->versions)->toBe([1, 2])
        ->and($rateVersion)->toBe(2)
        ->and($claimVersion)->toBe(2)
        ->and($executedTarget)->toBe($executionTarget)
        ->and($refreshEvidence?->targetPolicy)->toBe('order-primary-key')
        ->and($refreshEvidence?->targetStrategy)->toBe('refresh')
        ->and($refreshEvidence?->targetIdentityMatched)->toBeTrue()
        ->and($refreshEvidence?->proposalTargetIdentityFingerprint)
        ->toBe($refreshEvidence?->executionTargetIdentityFingerprint);
});

it('denies resource substitution before execution authorization', function (): void {
    $authorizer = new RecordingFreshnessAuthorizer;
    $this->app->instance(CapabilityAuthorizer::class, $authorizer);
    $executions = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.fresh-action',
        ability: 'update',
        resolveTarget: fn (): FreshnessTarget => new FreshnessTarget(1001, 72, 1),
    )->executionTarget(freshnessIdentityPolicy(
        fn (): FreshnessTarget => new FreshnessTarget(1002, 72, 2),
    ))->executeUsing(function () use (&$executions): string {
        $executions++;

        return 'executed';
    }));

    $result = $verdict->runBound(freshnessEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($result->evaluation->stage->value)->toBe('target_refresh')
        ->and($result->evaluation->decision->reason)->toBe('Execution target identity did not match the proposal target.')
        ->and($authorizer->versions)->toBe([1])
        ->and($executions)->toBe(0)
        ->and(freshnessEvidence()->latest()?->targetIdentityMatched)->toBeFalse();
});

it('captures proposal identity before an in-place refresh mutation', function (): void {
    $target = new FreshnessTarget(1001, 72, 1);
    $authorizer = new RecordingFreshnessAuthorizer;
    $this->app->instance(CapabilityAuthorizer::class, $authorizer);
    $verdict = app(VerdictManager::class);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.fresh-action',
        ability: 'update',
        resolveTarget: fn (): FreshnessTarget => $target,
    )->executionTarget(freshnessIdentityPolicy(
        function (ActionEnvelope $envelope, FreshnessTarget $proposalTarget): FreshnessTarget {
            $proposalTarget->id = 1002;

            return $proposalTarget;
        },
    ))->executeUsing(fn (): string => 'executed'));

    $result = $verdict->runBound(freshnessEnvelope());
    $evidence = freshnessEvidence()->latest();

    expect($result->executed)->toBeFalse()
        ->and($result->evaluation->stage->value)->toBe('target_refresh')
        ->and($evidence?->proposalTargetIdentityFingerprint)
        ->not->toBe($evidence?->executionTargetIdentityFingerprint);
});

it('re-authorizes changed authority from refreshed external state', function (): void {
    $authorizer = new RecordingFreshnessAuthorizer;
    $this->app->instance(CapabilityAuthorizer::class, $authorizer);
    $executions = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.fresh-action',
        ability: 'update',
        resolveTarget: fn (): FreshnessTarget => new FreshnessTarget(1001, 72, 1),
    )->executionTarget(freshnessIdentityPolicy(
        fn (): FreshnessTarget => new FreshnessTarget(1001, 91, 2),
    ))->executeUsing(function () use (&$executions): string {
        $executions++;

        return 'executed';
    }));

    $result = $verdict->runBound(freshnessEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($result->evaluation->stage->value)->toBe('execution')
        ->and($result->evaluation->decision->reason)->toBe('Target authority changed.')
        ->and($authorizer->versions)->toBe([1, 2])
        ->and($executions)->toBe(0);
});

it('records expected disappearance during refresh as a denial', function (): void {
    $authorizer = new RecordingFreshnessAuthorizer;
    $this->app->instance(CapabilityAuthorizer::class, $authorizer);
    $verdict = app(VerdictManager::class);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.fresh-action',
        ability: 'update',
        resolveTarget: fn (): FreshnessTarget => new FreshnessTarget(1001, 72, 1),
    )->executionTarget(freshnessIdentityPolicy(
        fn (): never => throw TargetNotResolvable::make(),
    ))->executeUsing(fn (): string => 'executed'));

    $result = $verdict->runBound(freshnessEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($result->evaluation->stage->value)->toBe('target_refresh')
        ->and($result->evaluation->decision->reason)->toBe(TargetNotResolvable::DECISION_REASON)
        ->and($result->evaluation->target)->toBeNull()
        ->and(freshnessEvidence()->latest()?->executionTargetIdentityFingerprint)->toBeNull();
});

it('propagates unexpected refresh faults without executing', function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new RecordingFreshnessAuthorizer);
    $executions = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.fresh-action',
        ability: 'update',
        resolveTarget: fn (): FreshnessTarget => new FreshnessTarget(1001, 72, 1),
    )->executionTarget(freshnessIdentityPolicy(
        fn (): never => throw new RuntimeException('Refresh store unavailable.'),
    ))->executeUsing(function () use (&$executions): string {
        $executions++;

        return 'executed';
    }));

    expect(fn () => $verdict->runBound(freshnessEnvelope()))
        ->toThrow(RuntimeException::class, 'Refresh store unavailable.');
    expect($executions)->toBe(0);
});

it('records explicit stale-snapshot acceptance and denies a missing policy', function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new RecordingFreshnessAuthorizer);
    $verdict = app(VerdictManager::class);
    $target = new FreshnessTarget(1001, 72, 1);
    $verdict->capability(Capability::usingPolicy(
        name: 'orders.fresh-action',
        ability: 'update',
        resolveTarget: fn (): FreshnessTarget => $target,
    )->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
        name: 'request-local-snapshot',
        identityUsing: fn (ActionEnvelope $envelope, FreshnessTarget $resolved): array => [
            'order_id' => $resolved->id,
        ],
    ))->executeUsing(fn (): string => 'executed'));

    expect($verdict->runBound(freshnessEnvelope())->executed)->toBeTrue()
        ->and(collect(freshnessEvidence()->all())->firstWhere('stage', 'target_refresh')?->targetStrategy)
        ->toBe('accept_stale_snapshot');

    $other = app(VerdictManager::class);
    $other->capability(Capability::usingPolicy(
        name: 'orders.missing-target-policy',
        ability: 'update',
        resolveTarget: fn (): FreshnessTarget => $target,
    )->executeUsing(fn (): string => 'executed'));
    $missingPolicy = ActionEnvelope::wrap(
        new ActionProposal('orders.missing-target-policy', ['order_id' => 1001]),
        new ActionContext(72),
    );
    $result = $other->runBound($missingPolicy);

    expect($result->executed)->toBeFalse()
        ->and($result->evaluation->decision->reason)
        ->toBe('Capability does not define an execution-target policy.');
});
