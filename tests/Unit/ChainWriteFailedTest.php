<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\Events\ChainWriteFailed;

it('carries the chain write failure facts', function (): void {
    $event = new ChainWriteFailed(
        chainId: 'verdict',
        correlationId: 'env-1',
        recordType: 'decision',
        phase: 'append',
        attempts: 3,
        message: 'Could not acquire append lock for chain: verdict',
    );

    expect($event->chainId)->toBe('verdict')
        ->and($event->correlationId)->toBe('env-1')
        ->and($event->recordType)->toBe('decision')
        ->and($event->phase)->toBe('append')
        ->and($event->attempts)->toBe(3)
        ->and($event->message)->toBe('Could not acquire append lock for chain: verdict');
});

it('allows a null correlation id', function (): void {
    $event = new ChainWriteFailed(
        chainId: 'verdict',
        correlationId: null,
        recordType: 'context_release',
        phase: 'append',
        attempts: 1,
        message: 'boom',
    );

    expect($event->correlationId)->toBeNull();
});

it('distinguishes a chain-id resolution failure from an exhausted append', function (): void {
    $event = new ChainWriteFailed(
        chainId: 'unknown',
        correlationId: 'env-1',
        recordType: 'decision',
        phase: 'resolve_chain_id',
        attempts: 0,
        message: 'tenant resolution failed',
    );

    expect($event->phase)->toBe('resolve_chain_id')
        ->and($event->attempts)->toBe(0);
});
