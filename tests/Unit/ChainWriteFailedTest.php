<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\Events\ChainWriteFailed;

it('carries the chain write failure facts', function (): void {
    $event = new ChainWriteFailed(
        chainId: 'verdict',
        correlationId: 'env-1',
        recordType: 'decision',
        attempts: 3,
        message: 'Could not acquire append lock for chain: verdict',
    );

    expect($event->chainId)->toBe('verdict')
        ->and($event->correlationId)->toBe('env-1')
        ->and($event->recordType)->toBe('decision')
        ->and($event->attempts)->toBe(3)
        ->and($event->message)->toBe('Could not acquire append lock for chain: verdict');
});

it('allows a null correlation id', function (): void {
    $event = new ChainWriteFailed(
        chainId: 'verdict',
        correlationId: null,
        recordType: 'context_release',
        attempts: 1,
        message: 'boom',
    );

    expect($event->correlationId)->toBeNull();
});
