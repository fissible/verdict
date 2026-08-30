<?php

declare(strict_types=1);

/**
 * #410: `release.sh` never ran the suite. CI does run on the release commit — `tests.yml` triggers
 * on pushes to `main` — but it is a detector rather than a gate: on the v0.13.1 release, CI started
 * at 17:21:14Z and the GitHub Release published at 17:21:23Z, nine seconds later with the run still
 * in flight. A failure would have arrived after the tag was already on Packagist.
 *
 * The commit that nothing gated is also the one that can break the two tests asserting on the files
 * it edits: `DocumentationConsistencyTest` — which `release.sh` cites by name in the comment above
 * its own `RELEASES.md` edit — and `CompatibilityMatrixConformanceTest`.
 *
 * These read `release.sh` as text, the same approach and the same stated limits as the release-gate
 * tests in AuditReleaseChangelogScriptTest: they prove the shape and ORDER of statements, not that
 * any of them execute. Column zero closes the uncalled-helper and `if`-body escapes, because this
 * script indents everything inside either. A heredoc does not.
 */
function releaseGateScript(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/release.sh');
}

/** A shell line with any trailing comment removed — a `#` outside quotes and everything after it. */
function releaseGateWithoutComment(string $line): string
{
    $single = false;
    $double = false;

    for ($index = 0, $length = strlen($line); $index < $length; $index++) {
        $character = $line[$index];

        if ($character === "'" && ! $double) {
            $single = ! $single;
        } elseif ($character === '"' && ! $single) {
            $double = ! $double;
        } elseif ($character === '#' && ! $single && ! $double) {
            return rtrim(substr($line, 0, $index));
        }
    }

    return rtrim($line);
}

/**
 * The line number of the one top-level executable statement matching $pattern.
 *
 * Column zero and comment-stripped, so neither a commented example nor a copy indented inside a
 * helper can stand in for the real thing.
 */
function releaseGateLine(string $pattern): int
{
    $found = [];

    foreach (explode("\n", releaseGateScript()) as $number => $line) {
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
            continue;
        }

        if (preg_match($pattern, releaseGateWithoutComment($line)) === 1) {
            $found[] = $number;
        }
    }

    expect($found)->toHaveCount(1, "exactly one top-level statement matching {$pattern}");

    return $found[0];
}

function releaseGateStatement(): string
{
    return releaseGateWithoutComment(explode("\n", releaseGateScript())[releaseGateLine('/^composer test\b/')]);
}

it('runs the suite after the release edits and before the commit and the tag', function (): void {
    $suite = releaseGateLine('/^composer test\b/');
    // The first write and the last edit block, both at column zero — the indented edits inside
    // those blocks cannot serve as anchors here, and the block boundaries bound them anyway.
    $versionWrite = releaseGateLine('/> VERSION$/');
    $lastEditBlock = releaseGateLine('/^if \[\[ -f RELEASES\.md \]\]/');
    $commit = releaseGateLine('/^git commit\b/');
    $tag = releaseGateLine('/^git tag\b/');

    // After the edits, because running before would only re-test what CI already covered on the
    // previous commit — the suite has to see the state that is about to be tagged. Before the
    // commit and the tag, because a suite run after either is a detector, which is what the tag
    // already had and what this issue exists to replace.
    expect($suite)->toBeGreaterThan($versionWrite)
        ->and($suite)->toBeGreaterThan($lastEditBlock)
        ->and($suite)->toBeLessThan($commit)
        ->and($suite)->toBeLessThan($tag);
});

it('lets a failing suite stop the release', function (): void {
    // One command and one `|| die`. Anything else in the statement either swallows the status
    // (`|| true`, `; :`, a pipeline without pipefail) or stops the release from waiting for it
    // (a trailing `&`) — and `|| die` is the shape every other guard in this script uses.
    expect(releaseGateStatement())->toMatch('/^composer test \|\| die "/')
        ->and(releaseGateScript())->toMatch('/^set -e/m')
        ->and(releaseGateScript())->not->toMatch('/^\s*set \+e/m');
});

it('does not discard the working tree when the suite fails', function (): void {
    $statement = releaseGateStatement();

    // The quoted message is allowed to NAME a reset command; the failure path must not run one.
    // Stripping the quoted string is what separates the two, and the distinction is the point:
    // release.sh already refuses to start on a dirty tree, so a revert here could not destroy
    // unrelated work — it would destroy the release-commit state, which is exactly what an
    // operator needs to see when DocumentationConsistencyTest rejects the RELEASES.md edit.
    $executable = preg_replace('/"[^"]*"/', '""', $statement) ?? '';

    expect($executable)->not->toContain('git checkout')
        ->and($executable)->not->toContain('git restore')
        ->and($executable)->not->toContain('git reset')
        ->and($executable)->not->toContain('git stash');
});

it('names the command that resets the working tree', function (): void {
    // Failing with a dirty tree is the deliberate choice, so the message has to carry the way out.
    // An operator reading this is mid-release and should not have to work out what release.sh
    // wrote before it stopped.
    expect(releaseGateStatement())->toContain('git checkout -- .');
});
