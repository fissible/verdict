<?php

declare(strict_types=1);

use Fissible\Verdict\Exceptions\EvidenceChainWriteFailed;

it('builds a message naming the chain, record type, and attempt count', function (): void {
    $previous = new RuntimeException('Could not acquire append lock for chain: verdict');

    $exception = EvidenceChainWriteFailed::fromFailure('verdict', 'decision', 3, $previous);

    expect($exception->getMessage())
        ->toBe('Failed to write [decision] evidence to attest chain [verdict] after 3 attempt(s).')
        ->and($exception->getPrevious())->toBe($previous);
});

it('allows a null previous exception', function (): void {
    $exception = EvidenceChainWriteFailed::fromFailure('verdict', 'decision', 1, null);

    expect($exception->getPrevious())->toBeNull();
});
