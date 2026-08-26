<?php

declare(strict_types=1);

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\ToolObservation;

function secretObservation(array $matched = [], array $registered = []): ToolObservation
{
    return new ToolObservation(
        'orders.search',
        str_repeat('a', 64),
        Disposition::Permit,
        true,
        $matched,
        $registered,
    );
}

it('defaults both registered-secret fields, leaving every existing construction site unchanged', function (): void {
    $observation = new ToolObservation('orders.search', str_repeat('a', 64), Disposition::Permit, true);

    expect($observation->matchedRegisteredSecrets)->toBe([])
        ->and($observation->registeredSecretLabels)->toBe([]);
});

it('carries the matched labels and the labels that were scanned for', function (): void {
    $observation = secretObservation(['order-canary'], ['order-canary', 'profile-canary']);

    expect($observation->matchedRegisteredSecrets)->toBe(['order-canary'])
        ->and($observation->registeredSecretLabels)->toBe(['order-canary', 'profile-canary']);
});

it('refuses a match for a canary it was never armed with', function (): void {
    // The invariant that keeps the pair honest: a matched label outside the registered set means
    // the two halves came from different places, and the observation can no longer be read as
    // "these canaries were scanned; these matched".
    expect(fn () => secretObservation(['ghost-canary'], ['order-canary']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects non-string entries in either list', function (): void {
    expect(fn () => new ToolObservation('orders.search', str_repeat('a', 64), Disposition::Permit, true, [], [1]))
        ->toThrow(InvalidArgumentException::class);
});
