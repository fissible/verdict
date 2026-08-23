<?php

declare(strict_types=1);

use Fissible\Verdict\Tests\Support\Evaluation\PackReferences;

require __DIR__.'/../vendor/autoload.php';

/**
 * Refreshes the committed pack baselines: runs the packs, rewrites each
 * baseline through the shipped verdict:evaluation-baseline command, and
 * normalizes file modes (the command's Filesystem::replace() marks written
 * files 0755). PHP rather than a shell loop so it behaves the same on every
 * contributor platform, and so the pack roster stays defined in exactly one
 * place (PackReferences::ALL).
 *
 * Usage: composer evaluation:refresh-baselines
 */
$root = dirname(__DIR__);

passthru(
    escapeshellarg(PHP_BINARY).' '.escapeshellarg("{$root}/scripts/run-attack-packs.php"),
    $code,
);

if ($code !== 0) {
    exit($code);
}

foreach (PackReferences::ALL as $reference) {
    $suite = $reference::SUITE;
    $baseline = "{$root}/tests/Baselines/{$suite}.json";

    passthru(implode(' ', array_map('escapeshellarg', [
        PHP_BINARY,
        "{$root}/vendor/bin/testbench",
        'verdict:evaluation-baseline',
        "{$root}/build/evaluation/{$suite}.report.json",
        $baseline,
        '--force',
    ])), $code);

    if ($code !== 0) {
        exit($code);
    }

    chmod($baseline, 0644);
}
