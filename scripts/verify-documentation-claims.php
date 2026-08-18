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
 * Resolve a follow-up issue's state via `gh`, without a shell. `proc_open` with an argument list
 * runs the binary directly — no `/bin/sh -c`, no string interpolation, nothing to escape — and gh
 * being unavailable (absent, unauthenticated, offline, a transient error) resolves to 'unknown',
 * never 'closed', so unavailability is never mistaken for a closed issue. Only reached when the
 * online freshness check is enabled (see the run below); default runs make no network call at all.
 */
function followUpIssueState(string $outcome): string
{
    preg_match('/^follow-up:#(\d+)$/', $outcome, $match);

    $process = @proc_open(
        ['gh', 'issue', 'view', $match[1], '--repo', 'fissible/verdict', '--json', 'state', '--jq', '.state'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        return 'unknown';
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return issueStateFrom(proc_close($process), is_string($output) ? $output : '');
}

/**
 * Map a `gh issue view` result to a claim state. A non-zero (or absent) exit is 'unknown' — gh could
 * not answer; a zero exit reporting anything other than OPEN is 'closed'.
 */
function issueStateFrom(?int $exitCode, string $output): string
{
    if ($exitCode !== 0) {
        return 'unknown';
    }

    return trim($output) === 'OPEN' ? 'open' : 'closed';
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

foreach (['/docs/security-model.md', '/docs/incident-response.md'] as $source) {
    foreach (documentedClaims($root.$source) as $id => $claim) {
        if (isset($claims[$id])) {
            throw new RuntimeException("Claim [{$id}] is documented in more than one file.");
        }

        $claims[$id] = $claim;
    }
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

// The follow-up-issue freshness check is the only step that touches the network. It is opt-in so a
// default `composer test` run is fully offline and makes no outbound call — CI enables it (see
// tests.yml) where gh is present and authenticated.
$online = filter_var(getenv('VERIFY_CLAIMS_ONLINE'), FILTER_VALIDATE_BOOL);
$skippedFollowUps = 0;

foreach ($claims as $id => $claim) {
    if ($claim['outcome'] === 'tested' && ! isset($annotations[$id])) {
        throw new RuntimeException("Tested claim [{$id}] has no proving test annotation.");
    }

    if ($claim['outcome'] === 'untestable' && $claim['reason'] === null) {
        throw new RuntimeException("Untestable claim [{$id}] needs an in-document reason.");
    }

    if (str_starts_with($claim['outcome'], 'follow-up:')) {
        if ($online) {
            enforceFollowUpState($id, followUpIssueState($claim['outcome']));
        } else {
            $skippedFollowUps++;
        }
    }
}

if ($skippedFollowUps > 0) {
    fwrite(STDERR, sprintf(
        "Notice: made no network call — skipped the open-issue freshness check for %d follow-up claim(s). Set VERIFY_CLAIMS_ONLINE=1 (CI does) to enforce it.\n",
        $skippedFollowUps,
    ));
}

foreach (array_keys($annotations) as $id) {
    if (! isset($claims[$id])) {
        throw new RuntimeException("Test annotation references undocumented claim [{$id}].");
    }
}

fwrite(STDOUT, sprintf("Verified %d documented claims.\n", count($claims)));
