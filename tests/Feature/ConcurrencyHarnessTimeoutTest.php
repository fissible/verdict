<?php

declare(strict_types=1);

use Fissible\Verdict\Tests\Support\ConcurrencyHarness;

/**
 * #359 (T3): `READY_TIMEOUT_SECONDS` bounds the readiness handshake, but the mutation/drain loop
 * that follows had no deadline at all. A child that wedged after release — a lock it never
 * acquires, a query that never returns — held the harness in `while ($remaining !== [])` until
 * GitHub killed the job, which reports as an opaque lane timeout rather than as the hung child it
 * actually is.
 *
 * These run on any driver. The harness bug is about process lifetime, not database semantics, so
 * unlike the concurrency tests these do not self-skip on SQLite — which matters, because the
 * concurrency matrix runs weekly and on tags rather than per-PR.
 */
function hangingChildScript(): string
{
    return __DIR__.'/../Support/concurrency-children/hangs-after-release.php';
}

it('gives up on a child that hangs after release instead of waiting forever', function (): void {
    $startedAt = microtime(true);

    $run = fn (): array => ConcurrencyHarness::run(
        hangingChildScript(),
        [['hang_seconds' => 8]],
        mutationTimeoutSeconds: 0.5,
    );

    expect($run)->toThrow(RuntimeException::class);

    // The deadline has to actually bound the wait, not merely be reported afterwards: without it
    // the harness returns when the child finally exits, and this assertion is what fails.
    expect(microtime(true) - $startedAt)->toBeLessThan(5.0);
});

it('names the mutation phase and the deadline when it gives up', function (): void {
    $run = fn (): array => ConcurrencyHarness::run(
        hangingChildScript(),
        [['hang_seconds' => 8]],
        mutationTimeoutSeconds: 0.5,
    );

    // A lane that dies on a hung child is only debuggable if the message says which phase timed
    // out — the readiness timeout already says its own, and two indistinguishable timeouts would
    // send the next reader to the wrong half of the harness.
    expect($run)->toThrow(RuntimeException::class, 'mutation');
});

it('lets a child that finishes within the deadline complete normally', function (): void {
    $results = ConcurrencyHarness::run(
        hangingChildScript(),
        [['hang_seconds' => 0]],
        mutationTimeoutSeconds: 10.0,
    );

    // The control: a deadline that fired on well-behaved children would turn every concurrency
    // test into a flake, which is worse than the hang it replaced.
    expect($results)->toHaveCount(1)
        ->and($results[0]['exit_code'])->toBe(0)
        ->and($results[0]['stdout'])->toContain('"ok":true');
});

it('reaps a hung child rather than orphaning it', function (): void {
    $before = runningChildProcessCount();

    try {
        ConcurrencyHarness::run(
            hangingChildScript(),
            [['hang_seconds' => 8]],
            mutationTimeoutSeconds: 0.5,
        );
    } catch (RuntimeException) {
        // expected
    }

    // The timeout must route through terminateAndReap() like every other failure path. A harness
    // that threw and left the child running would trade a visible hang for an invisible one — the
    // orphan still holds its connection and its locks against tables the test is about to drop.
    expect(runningChildProcessCount())->toBeLessThanOrEqual($before);
});

function runningChildProcessCount(): int
{
    $output = [];
    exec('pgrep -f hangs-after-release.php 2>/dev/null', $output);

    return count(array_filter($output, static fn (string $line): bool => trim($line) !== ''));
}
