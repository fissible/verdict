#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc !== 6) {
    fwrite(STDERR, "Usage: prepare-release-changelog.php <path> <version> <previous-tag> <new-tag> <date>\n");

    exit(1);
}

[, $path, $version, $previousTag, $newTag, $date] = $argv;

if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    fwrite(STDERR, "Version must use x.y.z format.\n");

    exit(1);
}

if ($newTag !== 'v'.$version) {
    fwrite(STDERR, "New tag must be v{$version}.\n");

    exit(1);
}

if (preg_match('/^v\d+\.\d+\.\d+$/', $previousTag) !== 1) {
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

$unreleasedPattern = '/^## \[Unreleased\]\R(?<body>.*?)(?=^## \[)/ms';
$matches = [];
$matchCount = preg_match_all($unreleasedPattern, $contents, $matches);

if ($matchCount !== 1) {
    fwrite(STDERR, "Changelog must contain exactly one Unreleased section followed by a release section.\n");

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
