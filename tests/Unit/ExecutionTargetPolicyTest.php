<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;

function targetPolicyEnvelope(): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal('orders.view', ['order_id' => 1001]),
        new ActionContext(72),
    );
}

it('requires named policies and canonical associative identities', function (): void {
    expect(fn () => ExecutionTargetPolicy::acceptStaleSnapshot('', fn (): array => ['id' => 1]))
        ->toThrow(InvalidArgumentException::class, 'must have a name')
        ->and(fn () => ExecutionTargetPolicy::acceptStaleSnapshot(
            'list-identity',
            fn (): array => [1001],
        )->identity(targetPolicyEnvelope(), new stdClass))
        ->toThrow(LogicException::class, 'must be an associative array')
        ->and(fn () => ExecutionTargetPolicy::acceptStaleSnapshot(
            'object-identity',
            fn (): array => ['target' => new stdClass],
        )->identity(targetPolicyEnvelope(), new stdClass))
        ->toThrow(LogicException::class, 'may only contain arrays, scalar values, and null');
});

it('makes stale snapshot reuse explicit', function (): void {
    $target = new stdClass;
    $policy = ExecutionTargetPolicy::acceptStaleSnapshot(
        'request-local-object',
        fn (): array => ['id' => 1001],
    );

    expect($policy->targetForExecution(targetPolicyEnvelope(), $target))->toBe($target)
        ->and($policy->strategy->value)->toBe('accept_stale_snapshot');
});
