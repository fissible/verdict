<?php

declare(strict_types=1);

// Load the verifier's pure helpers without running the verifier (no docs read, no gh shell-out).
// The state resolution is tested directly rather than by faking a `gh` binary over PATH: that
// shell-out is environment-fragile to stub, and the behaviour that matters — an unreachable gh is
// never mistaken for a closed issue — lives entirely in these pure functions.
if (! defined('VERIFY_CLAIMS_TESTING')) {
    define('VERIFY_CLAIMS_TESTING', true);
}

require_once dirname(__DIR__, 2).'/scripts/verify-documentation-claims.php';

it('maps a gh failure to unknown — offline is never a closed issue', function (): void {
    expect(issueStateFrom(1, []))->toBe('unknown')
        ->and(issueStateFrom(1, ['anything']))->toBe('unknown');
});

it('maps an OPEN issue to open and any other reported state to closed', function (): void {
    expect(issueStateFrom(0, ['OPEN']))->toBe('open')
        ->and(issueStateFrom(0, ['CLOSED']))->toBe('closed')
        ->and(issueStateFrom(0, ['']))->toBe('closed');
});

it('fails the build on a definitively closed follow-up', function (): void {
    expect(fn () => enforceFollowUpState('some.claim', 'closed'))
        ->toThrow(RuntimeException::class, 'closed issue');
});

it('does not fail the build when the follow-up state is unknown (offline-safe)', function (): void {
    enforceFollowUpState('some.claim', 'unknown');

    // Reaching here without throwing is the assertion: an unreachable gh warns, it does not fail.
    expect(true)->toBeTrue();
});

it('does not fail the build when the follow-up issue is open', function (): void {
    enforceFollowUpState('some.claim', 'open');

    expect(true)->toBeTrue();
});
