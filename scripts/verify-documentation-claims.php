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
        preg_match_all('/^### /m', $contents, $headings);
        preg_match_all('/^## Provenance derivation is deliberately incomplete$/m', $contents, $provenance);

        if (count($headings[0]) + count($provenance[0]) !== count($claims)) {
            throw new RuntimeException('Every limitations heading must carry exactly one @verdict-claim annotation.');
        }
    }

    return $claims;
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
}

foreach (array_keys($annotations) as $id) {
    if (! isset($claims[$id])) {
        throw new RuntimeException("Test annotation references undocumented claim [{$id}].");
    }
}

fwrite(STDOUT, sprintf("Verified %d documented claims.\n", count($claims)));
