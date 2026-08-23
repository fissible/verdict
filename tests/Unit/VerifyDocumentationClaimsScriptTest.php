<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

// Load the verifier's pure helpers without running the verifier (no docs read, no gh call). The
// state resolution is tested directly rather than by faking a `gh` binary — that shell-out is
// environment-fragile to stub, and the behaviour that matters (unreachable gh is never a closed
// issue) lives in these pure functions.
if (! defined('VERIFY_CLAIMS_TESTING')) {
    define('VERIFY_CLAIMS_TESTING', true);
}

require_once dirname(__DIR__, 2).'/scripts/verify-documentation-claims.php';

it('maps a gh failure to unknown — offline is never a closed issue', function (): void {
    expect(issueStateFrom(1, ''))->toBe('unknown')
        ->and(issueStateFrom(127, 'anything'))->toBe('unknown')
        ->and(issueStateFrom(null, ''))->toBe('unknown');
});

it('maps an OPEN issue to open and any other reported state to closed', function (): void {
    expect(issueStateFrom(0, "OPEN\n"))->toBe('open')
        ->and(issueStateFrom(0, 'CLOSED'))->toBe('closed')
        ->and(issueStateFrom(0, ''))->toBe('closed');
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

it('makes no network call by default — the freshness check is opt-in', function (): void {
    // env false unsets VERIFY_CLAIMS_ONLINE for the child, so this is deterministic even under CI
    // (where it is set). Offline means the gh branch is never entered — no subprocess, no PATH.
    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2).'/scripts/verify-documentation-claims.php'],
        env: ['VERIFY_CLAIMS_ONLINE' => false],
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('Verified')
        ->and($process->getErrorOutput())->toContain('made no network call');
});
