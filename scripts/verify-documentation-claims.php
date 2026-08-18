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

function followUpIssueIsOpen(string $outcome): bool
{
    preg_match('/^follow-up:#(\d+)$/', $outcome, $match);

    $command = sprintf(
        'gh issue view %d --repo fissible/verdict --json state --jq .state 2>/dev/null',
        (int) $match[1],
    );
    exec($command, $output, $exitCode);

    return $exitCode === 0 && trim(implode("\n", $output)) === 'OPEN';
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

foreach ($claims as $id => $claim) {
    if ($claim['outcome'] === 'tested' && ! isset($annotations[$id])) {
        throw new RuntimeException("Tested claim [{$id}] has no proving test annotation.");
    }

    if ($claim['outcome'] === 'untestable' && $claim['reason'] === null) {
        throw new RuntimeException("Untestable claim [{$id}] needs an in-document reason.");
    }

    if (str_starts_with($claim['outcome'], 'follow-up:') && ! followUpIssueIsOpen($claim['outcome'])) {
        throw new RuntimeException("Follow-up for claim [{$id}] must reference an open fissible/verdict issue.");
    }
}

foreach (array_keys($annotations) as $id) {
    if (! isset($claims[$id])) {
        throw new RuntimeException("Test annotation references undocumented claim [{$id}].");
    }
}

fwrite(STDOUT, sprintf("Verified %d documented claims.\n", count($claims)));
