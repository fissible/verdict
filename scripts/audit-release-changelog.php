#!/usr/bin/env php
<?php

declare(strict_types=1);

// Skeleton only: exists so the specification's failures are behavioural rather than
// "file not found". The decision logic is not implemented.

if ($argc !== 4) {
    fwrite(STDERR, "Usage: audit-release-changelog.php <changelog-path> <pull-requests-json-path> <commit-subjects-path>\n");

    exit(1);
}

exit(0);
