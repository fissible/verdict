<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return array{string, string} */
function verdictReleaseChangelogFixture(string $contents): array
{
    $directory = sys_get_temp_dir().'/verdict-release-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create the release-test directory.');
    }

    $path = $directory.'/CHANGELOG.md';
    file_put_contents($path, $contents);

    return [$directory, $path];
}

function runVerdictReleaseChangelogScript(string $path): Process
{
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 2).'/scripts/prepare-release-changelog.php',
        $path,
        '0.1.2',
        'v0.1.1',
        'v0.1.2',
        '2026-08-03',
    ]);
    $process->run();

    return $process;
}

function removeVerdictReleaseChangelogFixture(string $directory, string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }

    rmdir($directory);
}

it('promotes documentation-only notes without rewriting curated release history', function (): void {
    $original = <<<'MARKDOWN'
# Changelog

All notable changes to Verdict will be documented in this file.

## [Unreleased]

- Replace private VCS instructions with the Packagist command.

## [0.1.1] - 2026-08-02

- Preserve this hand-written historical note exactly.

[Unreleased]: https://github.com/fissible/verdict/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/fissible/verdict/compare/v0.1.0...v0.1.1
MARKDOWN;
    [$directory, $path] = verdictReleaseChangelogFixture($original);

    try {
        $process = runVerdictReleaseChangelogScript($path);
        $updated = file_get_contents($path);

        expect($process->isSuccessful())->toBeTrue()
            ->and($updated)->toContain("## [Unreleased]\n\n## [0.1.2] - 2026-08-03")
            ->and($updated)->toContain('- Replace private VCS instructions with the Packagist command.')
            ->and($updated)->toContain('- Preserve this hand-written historical note exactly.')
            ->and($updated)->toContain('[Unreleased]: https://github.com/fissible/verdict/compare/v0.1.2...HEAD')
            ->and($updated)->toContain('[0.1.2]: https://github.com/fissible/verdict/compare/v0.1.1...v0.1.2');
    } finally {
        removeVerdictReleaseChangelogFixture($directory, $path);
    }
});

it('refuses to prepare a release with no curated notes', function (): void {
    $original = <<<'MARKDOWN'
# Changelog

## [Unreleased]

## [0.1.1] - 2026-08-02

- Existing release.

[Unreleased]: https://github.com/fissible/verdict/compare/v0.1.1...HEAD
MARKDOWN;
    [$directory, $path] = verdictReleaseChangelogFixture($original);

    try {
        $process = runVerdictReleaseChangelogScript($path);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('Unreleased changelog section is empty.')
            ->and(file_get_contents($path))->toBe($original);
    } finally {
        removeVerdictReleaseChangelogFixture($directory, $path);
    }
});
