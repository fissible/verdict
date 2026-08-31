#!/usr/bin/env php
<?php

declare(strict_types=1);

// A first release has no previous tag: pass '' and supply the repository URL, which is what the
// changelog's comparison links are built from when there is no existing footer to extend.
if ($argc !== 6 && $argc !== 7) {
    fwrite(STDERR, "Usage: prepare-release-changelog.php <path> <version> <previous-tag|''> <new-tag> <date> [repository-url]\n");

    exit(1);
}

[, $path, $version, $previousTag, $newTag, $date] = $argv;
$repositoryUrl = rtrim((string) ($argv[6] ?? ''), '/');
$firstRelease = $previousTag === '';

if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    fwrite(STDERR, "Version must use x.y.z format.\n");

    exit(1);
}

if ($newTag !== 'v'.$version) {
    fwrite(STDERR, "New tag must be v{$version}.\n");

    exit(1);
}

if ($firstRelease) {
    if ($repositoryUrl === '') {
        fwrite(STDERR, "A first release (no previous tag) needs the repository URL to build the changelog links.\n");

        exit(1);
    }
} elseif (preg_match('/^v\d+\.\d+\.\d+$/', $previousTag) !== 1) {
    fwrite(STDERR, "Previous tag must use vx.y.z format.\n");

    exit(1);
}

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    fwrite(STDERR, "Date must use YYYY-MM-DD format.\n");

    exit(1);
}

$contents = @file_get_contents($path);

if ($contents === false) {
    fwrite(STDERR, "Unable to read changelog at {$path}.\n");

    exit(1);
}

if (str_contains($contents, "## [{$version}]")) {
    fwrite(STDERR, "Changelog already contains a {$version} release section.\n");

    exit(1);
}

// The Unreleased section ends at the next release heading, at the link footer, or — on a first
// release, where neither exists yet — at the end of the file.
$unreleasedPattern = '/^## \[Unreleased\]\R(?<body>.*?)(?=^## \[|^\[Unreleased\]: |\z)/ms';
$matches = [];
$matchCount = preg_match_all($unreleasedPattern, $contents, $matches);

if ($matchCount !== 1) {
    fwrite(STDERR, "Changelog must contain exactly one Unreleased section.\n");

    exit(1);
}

$unreleasedBody = trim((string) $matches['body'][0]);

if ($unreleasedBody === '') {
    fwrite(STDERR, "Unreleased changelog section is empty.\n");

    exit(1);
}

$releaseSection = "## [Unreleased]\n\n## [{$version}] - {$date}\n\n{$unreleasedBody}\n\n";
$updated = preg_replace($unreleasedPattern, $releaseSection, $contents, 1, $replaceCount);

if ($updated === null || $replaceCount !== 1) {
    fwrite(STDERR, "Unable to promote the Unreleased changelog section.\n");

    exit(1);
}

if ($firstRelease) {
    // A footer with no previous tag is a contradiction for a human to resolve, not one to paper over.
    if (preg_match('/^\[Unreleased\]: /m', $updated) === 1) {
        fwrite(STDERR, "The changelog already carries an Unreleased comparison link, but there is no previous tag to compare from.\n");

        exit(1);
    }

    // Create the footer the next release will extend. The first version links to its release page
    // rather than a comparison, because there is nothing earlier to compare against.
    $updated = rtrim($updated, "\n")."\n\n"
        ."[Unreleased]: {$repositoryUrl}/compare/{$newTag}...HEAD\n"
        ."[{$version}]: {$repositoryUrl}/releases/tag/{$newTag}\n";
} else {
    $linkPattern = '/^\[Unreleased\]: (?<base>\S+\/compare\/)'.preg_quote($previousTag, '/').'\.\.\.HEAD$/m';
    $linkMatches = [];
    $linkCount = preg_match_all($linkPattern, $updated, $linkMatches);

    if ($linkCount !== 1) {
        fwrite(STDERR, "Unreleased comparison link must target {$previousTag}...HEAD.\n");

        exit(1);
    }

    $base = (string) $linkMatches['base'][0];
    $releaseLinks = "[Unreleased]: {$base}{$newTag}...HEAD\n[{$version}]: {$base}{$previousTag}...{$newTag}";
    $updated = preg_replace($linkPattern, $releaseLinks, $updated, 1, $linkReplaceCount);

    if ($updated === null || $linkReplaceCount !== 1) {
        fwrite(STDERR, "Unable to update changelog comparison links.\n");

        exit(1);
    }
}

$directory = dirname($path);
$temporary = tempnam($directory, '.verdict-changelog-');

if ($temporary === false) {
    fwrite(STDERR, "Unable to create a temporary changelog file.\n");

    exit(1);
}

$permissions = @fileperms($path);

if (file_put_contents($temporary, $updated) === false) {
    @unlink($temporary);
    fwrite(STDERR, "Unable to write the prepared changelog.\n");

    exit(1);
}

if ($permissions !== false) {
    @chmod($temporary, $permissions & 0777);
}

if (! @rename($temporary, $path)) {
    @unlink($temporary);
    fwrite(STDERR, "Unable to replace the changelog atomically.\n");

    exit(1);
}
