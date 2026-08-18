<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * `verify:claims` runs on every `composer test` and in CI. It must be deterministic and offline:
 * the follow-up-issue freshness check shells out to `gh`, and a transient network / unauthenticated
 * / gh-missing failure must NOT be mistaken for a closed issue and fail the whole build. These
 * exercise the real script with a fake `gh` on PATH so the three states are covered without a
 * network call. A live `follow-up:#` claim in docs/limitations.md is what drives the check.
 */
function runClaimsVerifierWithFakeGh(string $fakeGhBody): Process
{
    $directory = sys_get_temp_dir().'/verdict-claims-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create the claims-test directory.');
    }

    $gh = $directory.'/gh';
    file_put_contents($gh, "#!/bin/sh\n".$fakeGhBody."\n");
    chmod($gh, 0755);

    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2).'/scripts/verify-documentation-claims.php'],
        env: ['PATH' => $directory.':'.getenv('PATH')],
    );
    $process->run();

    unlink($gh);
    rmdir($directory);

    return $process;
}

it('does not fail the build when gh is unavailable — offline is not a closed issue', function (): void {
    // gh absent / offline / unauthenticated all surface as a non-zero exit.
    $process = runClaimsVerifierWithFakeGh('exit 1');

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getErrorOutput())->toContain('could not verify');
});

it('still fails when a follow-up issue is genuinely closed', function (): void {
    $process = runClaimsVerifierWithFakeGh('echo CLOSED');

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())->toContain('closed');
});

it('passes when the follow-up issue is open', function (): void {
    $process = runClaimsVerifierWithFakeGh('echo OPEN');

    expect($process->isSuccessful())->toBeTrue();
});
