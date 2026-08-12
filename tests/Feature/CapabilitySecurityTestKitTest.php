<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Testing\CapabilitySecurityAssertionFailed;
use Fissible\Verdict\Testing\CapabilitySecurityTestKit;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Container\Container;

function capabilitySecurityKitEnvelope(string $capability, string $key, int $recordId = 1001): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal($capability, ['record_id' => $recordId], $key),
        new ActionContext(actor: 72, metadata: ['tenant_id' => 'tenant-a']),
    );
}

function permitCapabilitySecurityKitAuthorization(Container $app): void
{
    $app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
}

it('asserts policy denial through the bound execution path', function (): void {
    $executions = 0;
    $authorization = (object) ['called' => false];
    $this->app->instance(CapabilityAuthorizer::class, new class($authorization) implements CapabilityAuthorizer
    {
        public function __construct(private object $authorization) {}

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            $this->authorization->called = true;

            return Decision::deny('Application policy denied the action.');
        }
    });
    $capability = Capability::usingPolicy('records.remove', 'remove', fn (): object => (object) ['id' => 1001])
        ->executionTarget(acceptTestSnapshot())
        ->executeUsing(function () use (&$executions): void {
            $executions++;
        });

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    CapabilitySecurityTestKit::for($verdict, 'records.remove')->assertPolicyDenial(
        capabilitySecurityKitEnvelope('records.remove', 'deny-1'),
        fn (): bool => $authorization->called,
        function () use (&$executions): bool {
            return $executions === 0;
        },
    );

    expect($executions)->toBe(0);
});

it('asserts a refreshed target is the target observed by the executor', function (): void {
    permitCapabilitySecurityKitAuthorization($this->app);
    $proposalTarget = (object) ['id' => 1001, 'version' => 1];
    $executionTarget = (object) ['id' => 1001, 'version' => 2];
    $executedTarget = null;
    $capability = Capability::usingPolicy('records.refresh', 'update', fn (): object => $proposalTarget)
        ->executionTarget(ExecutionTargetPolicy::refresh(
            'record-id',
            fn (ActionEnvelope $envelope, object $target): array => ['id' => $target->id],
            fn (): object => $executionTarget,
        ))
        ->executeUsing(function ($action) use (&$executedTarget): void {
            $executedTarget = $action->target;
        });

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    CapabilitySecurityTestKit::for($verdict, 'records.refresh')->assertRefreshedTargetSubstitution(
        capabilitySecurityKitEnvelope('records.refresh', 'refresh-1'),
        function () use (&$executedTarget, $executionTarget): bool {
            return $executedTarget === $executionTarget;
        },
    );

    expect($executedTarget)->toBe($executionTarget);
});

it('asserts an approval cannot survive a binding change', function (): void {
    permitCapabilitySecurityKitAuthorization($this->app);
    $target = (object) ['id' => 1001, 'version' => 1];
    $executions = 0;
    $capability = Capability::usingPolicy('records.approve', 'update', fn (): object => $target)
        ->requiresConfirmation(
            fn (ActionEnvelope $envelope, object $record): array => ['record_id' => $record->id, 'version' => $record->version],
        )
        ->executionTarget(acceptTestSnapshot())
        ->executeUsing(function () use (&$executions): void {
            $executions++;
        });

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    CapabilitySecurityTestKit::for($verdict, 'records.approve')->assertApprovalBindingInvalidation(
        capabilitySecurityKitEnvelope('records.approve', 'approval-1'),
        'operator:72',
        function () use ($target): void {
            $target->version = 2;
        },
        function () use (&$executions): bool {
            return $executions === 0;
        },
    );

    expect($executions)->toBe(0);
});

it('names a missing approval idempotency key as an invalid fixture', function (): void {
    permitCapabilitySecurityKitAuthorization($this->app);
    $capability = Capability::usingPolicy('records.approval-key', 'update', fn (): object => (object) ['id' => 1001])
        ->requiresConfirmation(fn (): array => ['record_id' => 1001])
        ->executionTarget(acceptTestSnapshot())
        ->executeUsing(fn (): null => null);
    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);
    $envelope = ActionEnvelope::wrap(
        new ActionProposal('records.approval-key', ['record_id' => 1001]),
        new ActionContext(actor: 72),
    );

    expect(fn () => CapabilitySecurityTestKit::for($verdict, 'records.approval-key')->assertApprovalBindingInvalidation(
        $envelope,
        'operator:72',
        static function (): void {},
        static fn (): bool => true,
    ))->toThrow(CapabilitySecurityAssertionFailed::class, 'approval-binding-idempotency-key');
});

it('asserts an execution claim admits the same action once', function (): void {
    permitCapabilitySecurityKitAuthorization($this->app);
    $executions = 0;
    $capability = Capability::usingPolicy('records.claim', 'update', fn (): object => (object) ['id' => 1001])
        ->executionTarget(acceptTestSnapshot())
        ->atMostOnce(ExecutionClaimPolicy::named('record-once', fn (): array => ['record_id' => 1001]))
        ->executeUsing(function () use (&$executions): void {
            $executions++;
        });

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    CapabilitySecurityTestKit::for($verdict, 'records.claim')->assertExecutionClaimDuplicateAdmission(
        capabilitySecurityKitEnvelope('records.claim', 'claim-1'),
        capabilitySecurityKitEnvelope('records.claim', 'claim-1'),
        function () use (&$executions): bool {
            return $executions === 1;
        },
    );

    expect($executions)->toBe(1);
});

it('asserts a semantic rate limit blocks the next action', function (): void {
    permitCapabilitySecurityKitAuthorization($this->app);
    $executions = 0;
    $capability = Capability::usingPolicy('records.limit', 'update', fn (): object => (object) ['id' => 1001])
        ->executionTarget(acceptTestSnapshot())
        ->rateLimit(RateLimitPolicy::fixedWindow('one-record-update', 1, 60, fn (): array => ['actor_id' => 72]))
        ->executeUsing(function () use (&$executions): void {
            $executions++;
        });

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    CapabilitySecurityTestKit::for($verdict, 'records.limit')->assertRateLimitEnforcement(
        capabilitySecurityKitEnvelope('records.limit', 'limit-1'),
        [],
        capabilitySecurityKitEnvelope('records.limit', 'limit-2'),
        function () use (&$executions): bool {
            return $executions === 1;
        },
    );

    expect($executions)->toBe(1);
});

it('asserts an executor failure leaves its admission indeterminate', function (): void {
    permitCapabilitySecurityKitAuthorization($this->app);
    $capability = Capability::usingPolicy('records.failure', 'update', fn (): object => (object) ['id' => 1001])
        ->executionTarget(acceptTestSnapshot())
        ->atMostOnce(ExecutionClaimPolicy::named(
            'record-failure',
            fn (ActionEnvelope $envelope): array => ['record_id' => $envelope->proposal->arguments['record_id']],
        ))
        ->executeUsing(function (): never {
            throw new RuntimeException('The application executor failed.');
        });

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    CapabilitySecurityTestKit::for($verdict, 'records.failure')->assertExecutorFailureLeavesIndeterminateClaim(
        capabilitySecurityKitEnvelope('records.failure', 'failure-1'),
        RuntimeException::class,
        fn (): bool => true,
    );

    expect(true)->toBeTrue();
});

it('does not mistake a stale indeterminate claim for a refresh failure', function (): void {
    permitCapabilitySecurityKitAuthorization($this->app);
    $target = (object) ['id' => 1001];
    $capability = Capability::usingPolicy('records.failure-refresh', 'update', fn (): object => $target)
        ->executionTarget(ExecutionTargetPolicy::refresh(
            'record-id',
            fn (ActionEnvelope $envelope, object $record): array => ['id' => $record->id],
            function (ActionEnvelope $envelope) use ($target): object {
                if ($envelope->proposal->arguments['record_id'] === 1001) {
                    throw new RuntimeException('The refreshed target disappeared.');
                }

                return $target;
            },
        ))
        ->atMostOnce(ExecutionClaimPolicy::named(
            'record-failure-refresh',
            fn (ActionEnvelope $envelope): array => ['record_id' => $envelope->proposal->arguments['record_id']],
        ))
        ->executeUsing(function (): never {
            throw new RuntimeException('The application executor failed.');
        });
    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    try {
        $verdict->runBound(capabilitySecurityKitEnvelope('records.failure-refresh', 'failure-stale', 1002));
    } catch (RuntimeException) {
        // Seed a prior claim; the asserted run below fails before claim admission.
    }

    expect(fn () => CapabilitySecurityTestKit::for($verdict, 'records.failure-refresh')
        ->assertExecutorFailureLeavesIndeterminateClaim(
            capabilitySecurityKitEnvelope('records.failure-refresh', 'failure-refresh', 1001),
            RuntimeException::class,
            static fn (): bool => true,
        ))->toThrow(CapabilitySecurityAssertionFailed::class, 'executor-failure-indeterminate-claim');
});
