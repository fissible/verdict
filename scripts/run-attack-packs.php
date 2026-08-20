<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\CaseStatus;
use Fissible\Verdict\Tests\Support\Evaluation\AccountRecoveryReference;
use Fissible\Verdict\Tests\Support\Evaluation\RagBorneInjectionReference;
use Fissible\Verdict\Tests\Support\Evaluation\StorefrontReference;
use Fissible\Verdict\Tests\Support\Evaluation\ToolIntegrityReference;

require __DIR__.'/../vendor/autoload.php';

/**
 * Runs the four shipped deterministic attack packs against their reference
 * runners and writes one evaluation report per pack. No network, no provider,
 * no credentials — the same synthetic execution path the unit tests use.
 *
 * Usage: php scripts/run-attack-packs.php [output-dir]
 *
 * Compare a report against its committed baseline (tests/Baselines) with
 * verdict:evaluation-compare; refresh baselines with
 * `composer evaluation:refresh-baselines`.
 */
const ATTACK_PACK_REFERENCES = [
    AccountRecoveryReference::class,
    RagBorneInjectionReference::class,
    StorefrontReference::class,
    ToolIntegrityReference::class,
];

$outDir = rtrim($argv[1] ?? __DIR__.'/../build/evaluation', '/');

if (! is_dir($outDir) && ! mkdir($outDir, 0755, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Cannot create output directory [{$outDir}].".PHP_EOL);
    exit(1);
}

foreach (ATTACK_PACK_REFERENCES as $reference) {
    $result = $reference::suite()->run();
    $path = "{$outDir}/".$reference::SUITE.'.report.json';

    if (file_put_contents($path, $result->report()->toJson().PHP_EOL) === false) {
        fwrite(STDERR, "Cannot write report [{$path}].".PHP_EOL);
        exit(1);
    }

    $counts = [];

    foreach ($result->cases as $case) {
        $counts[$case->status->value] = ($counts[$case->status->value] ?? 0) + 1;
    }

    $summary = implode(', ', array_map(
        static fn (string $status, int $count): string => "{$count} {$status}",
        array_keys($counts),
        $counts,
    ));

    echo $reference::SUITE.': '.count($result->cases)." cases — {$summary}".PHP_EOL;

    foreach ($result->cases as $case) {
        if ($case->status === CaseStatus::Pending) {
            echo "  pending: {$case->id} (blocked by {$case->blockedBy}) — does not fail the comparison".PHP_EOL;
        }
    }
}

echo PHP_EOL."Reports written to [{$outDir}]. Compare with verdict:evaluation-compare against tests/Baselines;".PHP_EOL;
echo 'after an intentional, reviewed pack change refresh with [composer evaluation:refresh-baselines].'.PHP_EOL;
