<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;

it('requires a named execution-claim policy', function (): void {
    expect(fn () => ExecutionClaimPolicy::named(' ', fn (): array => ['order_id' => 1]))
        ->toThrow(InvalidArgumentException::class, 'must have a name');
});

it('requires an associative canonical execution-claim binding', function (mixed $binding, string $message): void {
    $policy = ExecutionClaimPolicy::named('cancel-order', fn (): mixed => $binding);
    $envelope = ActionEnvelope::wrap(new ActionProposal('orders.cancel'), new ActionContext(72));

    expect(fn () => $policy->binding($envelope, null))->toThrow(LogicException::class, $message);
})->with([
    'scalar' => [72, 'associative array'],
    'list' => [[['order-1002']], 'associative array'],
    'object value' => [['target' => new stdClass], 'arrays, scalar values, and null'],
]);
