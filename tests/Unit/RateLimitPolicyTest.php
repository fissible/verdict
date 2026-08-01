<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\RateLimits\RateLimitPolicy;

it('validates fixed-window policy configuration', function (string $name, int $limit, int $window, string $message): void {
    expect(fn () => RateLimitPolicy::fixedWindow($name, $limit, $window, fn (): array => ['actor' => 1]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    [' ', 1, 60, 'must have a name'],
    ['per-principal', 0, 60, 'limit must be at least one'],
    ['per-principal', 1, 0, 'window must be at least one second'],
]);

it('rejects non-canonical rate-limit bindings', function (): void {
    $policy = RateLimitPolicy::fixedWindow(
        'per-principal',
        1,
        60,
        fn (): array => ['actor' => new stdClass],
    );
    $envelope = ActionEnvelope::wrap(new ActionProposal('orders.view'), new ActionContext(72));

    expect(fn () => $policy->binding($envelope, null))
        ->toThrow(LogicException::class, 'arrays, scalar values, and null');
});

it('requires an associative rate-limit binding', function (mixed $binding): void {
    $policy = RateLimitPolicy::fixedWindow('per-principal', 1, 60, fn (): mixed => $binding);
    $envelope = ActionEnvelope::wrap(new ActionProposal('orders.view'), new ActionContext(72));

    expect(fn () => $policy->binding($envelope, null))
        ->toThrow(LogicException::class, 'must be an associative array');
})->with([
    'scalar' => 72,
    'list' => [['customer-72']],
]);
