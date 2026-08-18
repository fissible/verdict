<?php

declare(strict_types=1);

/** @return array<string, array{outcome: string, reason: string|null}> */
function documentedClaims(string $path, bool $limitations = false): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read [{$path}].");
    }

    preg_match_all('/<!-- @verdict-claim ([a-z0-9.-]+) (tested|untestable|follow-up:#\d+)(?: reason="([^"]+)")? -->/', $contents, $matches, PREG_SET_ORDER);
    $claims = [];

    foreach ($matches as $match) {
        if (isset($claims[$match[1]])) {
            throw new RuntimeException("Claim [{$match[1]}] is documented more than once in [{$path}].");
        }

        $claims[$match[1]] = ['outcome' => $match[2], 'reason' => $match[3] ?? null];
    }

    if ($limitations) {
        if (preg_match('/^## What Verdict does not guarantee\R(?<claims>.*?)(?=^## Operational responsibilities$)/ms', $contents, $section) !== 1) {
            throw new RuntimeException('Cannot find the limitations claim section.');
        }

        preg_match_all('/^#{2,3} /m', $section['claims'], $headings);

        if (count($headings[0]) !== count($claims)) {
            throw new RuntimeException('Every limitations heading must carry exactly one @verdict-claim annotation.');
        }
    }

    return $claims;
}

/**
 * Resolve a follow-up issue's state without letting the network fail a deterministic build.
 * `gh` exiting non-zero — absent, unauthenticated, offline, or a transient GitHub error — resolves
 * to 'unknown', never 'closed', so unavailability can never be mistaken for a closed issue. Only a
 * definitive 'closed' is a claim error; 'unknown' degrades to a warning at the call site.
 */
function followUpIssueState(string $outcome): string
{
    preg_match('/^follow-up:#(\d+)$/', $outcome, $match);

    exec(sprintf(
        'gh issue view %d --repo fissible/verdict --json state --jq .state 2>/dev/null',
        (int) $match[1],
    ), $output, $exitCode);

    return issueStateFrom($exitCode, $output);
}

/**
 * Map a `gh issue view` result to a claim state. A non-zero exit is 'unknown' (gh could not answer);
 * a zero exit reporting anything other than OPEN is 'closed'.
 *
 * @param  array<int, string>  $output
 */
function issueStateFrom(int $exitCode, array $output): string
{
    if ($exitCode !== 0) {
        return 'unknown';
    }

    return trim(implode("\n", $output)) === 'OPEN' ? 'open' : 'closed';
}

/**
 * Enforce a follow-up claim's resolved state. A definitively closed issue is a hard error; an
 * 'unknown' state (gh unavailable / offline) degrades to a warning so the deterministic suite is
 * never blocked by the network; an open issue passes silently.
 */
function enforceFollowUpState(string $id, string $state): void
{
    if ($state === 'closed') {
        throw new RuntimeException("Follow-up for claim [{$id}] references a closed issue; point it at an open fissible/verdict issue.");
    }

    if ($state === 'unknown') {
        fwrite(STDERR, "Notice: could not verify the follow-up issue for claim [{$id}] (gh unavailable or offline); skipping the open-issue freshness check.\n");
    }
}

// A test requires this file to exercise the pure helpers above; return before the verifier runs so
// loading it never reads the docs or shells out. Absent the constant (the real `@php` run), proceed.
if (defined('VERIFY_CLAIMS_TESTING')) {
    return;
}

$root = dirname(__DIR__);
$claims = documentedClaims($root.'/docs/limitations.md', true);

foreach (documentedClaims($root.'/docs/security-model.md') as $id => $claim) {
    if (isset($claims[$id])) {
        throw new RuntimeException("Claim [{$id}] is documented in more than one file.");
    }

    $claims[$id] = $claim;
}
$annotations = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests')) as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $contents = file_get_contents($path);
    preg_match_all('/@verdict-claim ([a-z0-9.-]+)/', $contents ?: '', $matches);

    foreach ($matches[1] as $claim) {
        $annotations[$claim] = true;
    }
}

foreach ($claims as $id => $claim) {
    if ($claim['outcome'] === 'tested' && ! isset($annotations[$id])) {
        throw new RuntimeException("Tested claim [{$id}] has no proving test annotation.");
    }

    if ($claim['outcome'] === 'untestable' && $claim['reason'] === null) {
        throw new RuntimeException("Untestable claim [{$id}] needs an in-document reason.");
    }

    if (str_starts_with($claim['outcome'], 'follow-up:')) {
        enforceFollowUpState($id, followUpIssueState($claim['outcome']));
    }
}

foreach (array_keys($annotations) as $id) {
    if (! isset($claims[$id])) {
        throw new RuntimeException("Test annotation references undocumented claim [{$id}].");
    }
}

fwrite(STDOUT, sprintf("Verified %d documented claims.\n", count($claims)));
