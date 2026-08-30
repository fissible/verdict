#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: audit-release-changelog.php <changelog-path> <pull-requests-json-path> <commit-subjects-path>\n");

    exit(1);
}

[, $changelogPath, $pullRequestsPath, $subjectsPath] = $argv;

foreach ([$changelogPath, $pullRequestsPath, $subjectsPath] as $path) {
    if (! is_file($path) || ! is_readable($path)) {
        fwrite(STDERR, "Unable to read input at {$path}.\n");

        exit(1);
    }
}

$changelog = @file_get_contents($changelogPath);
$pullRequestsJson = @file_get_contents($pullRequestsPath);
$subjects = @file_get_contents($subjectsPath);

if ($changelog === false || $pullRequestsJson === false || $subjects === false) {
    fwrite(STDERR, "Unable to read release-audit input.\n");

    exit(1);
}

$unreleasedMatches = [];
$unreleasedCount = preg_match_all('/^## \[Unreleased\]\R(?<body>.*?)(?=^## \[\d+\.\d+\.\d+\])/ms', $changelog, $unreleasedMatches);
$unreleasedHeadings = preg_match_all('/^## \[Unreleased\]$/m', $changelog);

if ($unreleasedCount !== 1 || $unreleasedHeadings !== 1) {
    fwrite(STDERR, "Changelog must contain exactly one Unreleased section followed by a release section.\n");

    exit(1);
}

$pullRequests = json_decode($pullRequestsJson);

if (json_last_error() !== JSON_ERROR_NONE || ! is_array($pullRequests) || ! array_is_list($pullRequests)) {
    fwrite(STDERR, "Pull-request JSON must be a list.\n");

    exit(1);
}

$byNumber = [];
$numberCounts = [];

foreach ($pullRequests as $index => $pullRequest) {
    if (! is_object($pullRequest)
        || ! property_exists($pullRequest, 'number') || ! is_int($pullRequest->number)
        || ! property_exists($pullRequest, 'title') || ! is_string($pullRequest->title)
        || ! property_exists($pullRequest, 'labels') || ! is_array($pullRequest->labels) || ! array_is_list($pullRequest->labels)
        || ! property_exists($pullRequest, 'files') || ! is_array($pullRequest->files) || ! array_is_list($pullRequest->files)
        || ! property_exists($pullRequest, 'closingIssuesReferences') || ! is_array($pullRequest->closingIssuesReferences) || ! array_is_list($pullRequest->closingIssuesReferences)) {
        fwrite(STDERR, "Malformed pull-request record at index {$index}.\n");

        exit(1);
    }

    foreach ($pullRequest->labels as $label) {
        if (! is_object($label) || ! property_exists($label, 'name') || ! is_string($label->name)) {
            fwrite(STDERR, "Malformed label in pull-request record {$pullRequest->number}.\n");

            exit(1);
        }
    }

    foreach ($pullRequest->files as $file) {
        if (! is_object($file) || ! property_exists($file, 'path') || ! is_string($file->path)) {
            fwrite(STDERR, "Malformed file in pull-request record {$pullRequest->number}.\n");

            exit(1);
        }
    }

    foreach ($pullRequest->closingIssuesReferences as $issue) {
        if (! is_object($issue) || ! property_exists($issue, 'number') || ! is_int($issue->number)) {
            fwrite(STDERR, "Malformed closing issue in pull-request record {$pullRequest->number}.\n");

            exit(1);
        }
    }

    $numberCounts[$pullRequest->number] = ($numberCounts[$pullRequest->number] ?? 0) + 1;
    $byNumber[$pullRequest->number] ??= $pullRequest;
}

$inRange = [];

foreach (preg_split('/\R/', $subjects) ?: [] as $subject) {
    if ($subject === '') {
        continue;
    }

    if (preg_match('/\s\(#(?<number>\d+)\)$/', $subject, $matches) !== 1) {
        fwrite(STDERR, "Unable to attribute commit subject to a pull request: {$subject}\n");

        continue;
    }

    $inRange[(int) $matches['number']] = true;
}

foreach (array_keys($inRange) as $number) {
    if (! isset($byNumber[$number])) {
        fwrite(STDERR, "Pull request #{$number} from the release range was not returned by the fetch; either its trailing number is not a pull-request squash-merge reference, or the fetch is incomplete.\n");

        exit(1);
    }

    if ($numberCounts[$number] !== 1) {
        fwrite(STDERR, "Pull request #{$number} appears more than once in fetched JSON.\n");

        exit(1);
    }
}

$unreleasedBody = (string) $unreleasedMatches['body'][0];
$undecided = [];

foreach (array_keys($inRange) as $number) {
    $pullRequest = $byNumber[$number];
    $externallyShipped = false;

    foreach ($pullRequest->files as $file) {
        if (preg_match('#^(src|config|database)/#', $file->path) === 1) {
            $externallyShipped = true;

            break;
        }
    }

    if (! $externallyShipped) {
        continue;
    }

    $decided = false;

    foreach ($pullRequest->labels as $label) {
        if ($label->name === 'release: no changelog') {
            $decided = true;

            break;
        }
    }

    if (! $decided) {
        $references = [$pullRequest->number];

        foreach ($pullRequest->closingIssuesReferences as $issue) {
            $references[] = $issue->number;
        }

        foreach ($references as $reference) {
            if (preg_match('/(?<![A-Za-z0-9_])#'.preg_quote((string) $reference, '/').'(?!\d)/', $unreleasedBody) === 1) {
                $decided = true;

                break;
            }
        }
    }

    if (! $decided) {
        $undecided[] = "#{$pullRequest->number} {$pullRequest->title}";
    }
}

if ($undecided !== []) {
    fwrite(STDERR, "Externally shipped pull requests need a changelog entry or release: no changelog label:\n");

    foreach ($undecided as $pullRequest) {
        fwrite(STDERR, "- {$pullRequest}\n");
    }

    exit(1);
}
