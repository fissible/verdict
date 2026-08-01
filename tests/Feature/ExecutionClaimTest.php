<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimOutcome;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimResolution;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimTransition;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\VerdictManager;

final readonly class ExecutionClaimTarget
{
    public function __construct(public int $id, public int $version = 1) {}
}

function executionClaimEnvelope(
    int $orderId = 1002,
    string $toolCallId = 'tool-call-1',
    string $reason = 'Customer request',
): ActionEnvelope {
    return ActionEnvelope::wrap(
        proposal: new ActionProposal(
            capability: 'orders.cancel-once',
            arguments: ['order_id' => $orderId, 'reason' => $reason],
            idempotencyKey: $toolCallId,
        ),
        context: new ActionContext(72, ['tenant_id' => 'store-1']),
    );
}

/** @param callable(AuthorizedAction): string $executor */
function executionClaimCapability(callable $executor): Capability
{
    return Capability::usingPolicy(
        name: 'orders.cancel-once',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): ExecutionClaimTarget => new ExecutionClaimTarget(
            (int) $envelope->proposal->arguments['order_id'],
        ),
    )->atMostOnce(ExecutionClaimPolicy::named(
        'cancel-order-version',
        fn (ActionEnvelope $envelope, ExecutionClaimTarget $target): array => [
            'tenant_id' => $envelope->context->metadata['tenant_id'],
            'actor_id' => $envelope->context->actor,
            'order_id' => $target->id,
            'order_version' => $target->version,
        ],
    ))->executeUsing($executor);
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

it('admits one logical operation across different transport IDs and non-material arguments', function (): void {
    $executions = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(executionClaimCapability(function () use (&$executions): string {
        $executions++;

        return 'cancelled';
    }));

    $first = $verdict->runBound(executionClaimEnvelope(toolCallId: 'provider-call-a', reason: 'First wording'));
    $duplicate = $verdict->runBound(executionClaimEnvelope(toolCallId: 'provider-call-b', reason: 'Changed wording'));

    expect($first->executed)->toBeTrue()
        ->and($duplicate->executed)->toBeFalse()
        ->and($duplicate->evaluation->stage->value)->toBe('execution_claim')
        ->and($duplicate->evaluation->decision->reason)->toBe('Logical operation was already completed.')
        ->and($executions)->toBe(1);

    $evidence = app(EvidenceRecorder::class);
    expect($evidence)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if ($evidence instanceof InMemoryEvidenceRecorder) {
        $claims = collect($evidence->all())->where('stage', 'execution_claim')->values();

        expect($claims)->toHaveCount(3)
            ->and($claims[0]->executionClaimStatus)->toBe('claimed')
            ->and($claims[1]->executionClaimStatus)->toBe('completed')
            ->and($claims[2]->executionClaimStatus)->toBe('completed')
            ->and($claims[0]->executionClaimFingerprint)->toHaveLength(64)
            ->and($claims[0]->executionClaimBindingFingerprint)->toHaveLength(64);
    }
});

it('blocks a re-entrant duplicate while the first executor is active', function (): void {
    $verdict = app(VerdictManager::class);
    $executions = 0;
    $nested = null;
    $verdict->capability(executionClaimCapability(function () use (&$executions, &$nested, $verdict): string {
        $executions++;
        $nested = $verdict->runBound(executionClaimEnvelope(toolCallId: 'nested-call'));

        return 'cancelled';
    }));

    $outer = $verdict->runBound(executionClaimEnvelope(toolCallId: 'outer-call'));

    expect($outer->executed)->toBeTrue()
        ->and($nested)->not->toBeNull()
        ->and($nested?->executed)->toBeFalse()
        ->and($nested?->evaluation->decision->reason)->toBe('Logical operation already has an active execution claim.')
        ->and($executions)->toBe(1);
});

it('marks a thrown execution indeterminate and blocks later admission', function (): void {
    $verdict = app(VerdictManager::class);
    $executions = 0;
    $verdict->capability(executionClaimCapability(function () use (&$executions): string {
        $executions++;
        throw new RuntimeException('Carrier response was ambiguous.');
    }));

    expect(fn () => $verdict->runBound(executionClaimEnvelope()))
        ->toThrow(RuntimeException::class, 'Carrier response was ambiguous.');

    $duplicate = $verdict->runBound(executionClaimEnvelope(toolCallId: 'retry-call'));

    expect($duplicate->executed)->toBeFalse()
        ->and($duplicate->evaluation->decision->reason)
        ->toBe('Logical operation has an indeterminate execution outcome.')
        ->and($executions)->toBe(1)
        ->and($verdict->executionClaims()->unresolved())->toHaveCount(1)
        ->and($verdict->executionClaims()->unresolved()[0]->status->value)->toBe('indeterminate');
});

it('keeps distinct canonical operations independent', function (): void {
    $verdict = app(VerdictManager::class);
    $executions = 0;
    $verdict->capability(executionClaimCapability(function () use (&$executions): string {
        $executions++;

        return 'cancelled';
    }));

    expect($verdict->runBound(executionClaimEnvelope(orderId: 1002))->executed)->toBeTrue()
        ->and($verdict->runBound(executionClaimEnvelope(orderId: 1003))->executed)->toBeTrue()
        ->and($executions)->toBe(2);
});

it('consumes the semantic rate limit before attempting an execution claim', function (): void {
    $verdict = app(VerdictManager::class);
    $executions = 0;
    $capability = executionClaimCapability(function () use (&$executions): string {
        $executions++;

        return 'cancelled';
    })->rateLimit(RateLimitPolicy::fixedWindow(
        name: 'one-cancellation-per-customer',
        limit: 1,
        windowSeconds: 60,
        keyUsing: fn (ActionEnvelope $envelope): array => [
            'tenant_id' => $envelope->context->metadata['tenant_id'],
            'actor_id' => $envelope->context->actor,
        ],
    ));
    $verdict->capability($capability);

    $first = $verdict->runBound(executionClaimEnvelope(orderId: 1002));
    $throttled = $verdict->runBound(executionClaimEnvelope(orderId: 1003));

    expect($first->executed)->toBeTrue()
        ->and($throttled->executed)->toBeFalse()
        ->and($throttled->evaluation->stage->value)->toBe('rate_limit')
        ->and($executions)->toBe(1);

    $evidence = app(EvidenceRecorder::class);
    expect($evidence)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if ($evidence instanceof InMemoryEvidenceRecorder) {
        expect(collect($evidence->all())->where('stage', 'execution_claim'))->toHaveCount(2);
    }
});

it('propagates claim-store failures without admitting the executor', function (): void {
    $this->app->instance(ExecutionClaimStore::class, new class implements ExecutionClaimStore
    {
        public function claim(ExecutionClaim $claim): ExecutionClaimTransition
        {
            throw new RuntimeException('Execution-claim database unavailable.');
        }

        public function complete(string $claimId, DateTimeImmutable $at): ExecutionClaimTransition
        {
            return ExecutionClaimTransition::to(ExecutionClaimOutcome::NotFound);
        }

        public function markIndeterminate(string $claimId, DateTimeImmutable $at): ExecutionClaimTransition
        {
            return ExecutionClaimTransition::to(ExecutionClaimOutcome::NotFound);
        }

        public function resolve(
            string $claimId,
            ExecutionClaimResolution $resolution,
            string $resolvedBy,
            string $reason,
            DateTimeImmutable $at,
        ): ExecutionClaimTransition {
            return ExecutionClaimTransition::to(ExecutionClaimOutcome::NotFound);
        }

        public function find(string $claimId): ?ExecutionClaim
        {
            return null;
        }

        public function unresolved(): array
        {
            return [];
        }
    });
    $executions = 0;
    $verdict = app(VerdictManager::class);
    $verdict->capability(executionClaimCapability(function () use (&$executions): string {
        $executions++;

        return 'cancelled';
    }));

    expect(fn () => $verdict->runBound(executionClaimEnvelope()))
        ->toThrow(RuntimeException::class, 'Execution-claim database unavailable.');
    expect($executions)->toBe(0);
});
