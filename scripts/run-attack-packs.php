<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Evaluation\CasePurpose;
use Fissible\Verdict\Evaluation\CaseStatus;
use Fissible\Verdict\Tests\Support\Evaluation\PackReferences;

require __DIR__.'/../vendor/autoload.php';

/**
 * Runs the shipped deterministic attack packs against their reference runners
 * and writes one evaluation report per pack. No network, no provider, no
 * credentials — the same synthetic execution path the unit tests use. The
 * report clock is pinned, so two runs over unchanged packs produce
 * byte-identical reports and a baseline refresh that changes no behaviour
 * produces no diff.
 *
 * Usage: php scripts/run-attack-packs.php [output-dir]
 *
 * Compare a report against its committed baseline (tests/Baselines) with
 * verdict:evaluation-compare; refresh baselines with
 * `composer evaluation:refresh-baselines`.
 */
$outDir = rtrim($argv[1] ?? __DIR__.'/../build/evaluation', '/') ?: '/';

if (! is_dir($outDir) && ! mkdir($outDir, 0755, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Cannot create output directory [{$outDir}].".PHP_EOL);
    exit(1);
}

$clock = new class implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    }
};

foreach (PackReferences::ALL as $reference) {
    $result = $reference::suite()->run($clock);
    $path = "{$outDir}/".$reference::SUITE.'.report.json';

    if (file_put_contents($path, $result->report()->toJson().PHP_EOL) === false) {
        fwrite(STDERR, "Cannot write report [{$path}].".PHP_EOL);
        exit(1);
    }

    $security = $result->score(CasePurpose::Security);
    $utility = $result->score(CasePurpose::Utility);

    printf(
        '%s: %d cases — %d passed, %d failed, %d errors, %d pending%s',
        $reference::SUITE,
        count($result->cases),
        $security->passed + $utility->passed,
        $security->failed + $utility->failed,
        $security->errors + $utility->errors,
        $security->pending + $utility->pending,
        PHP_EOL,
    );

    foreach ($result->cases as $case) {
        if ($case->status === CaseStatus::Pending) {
            echo "  pending: {$case->id} (blocked by {$case->blockedBy}) — does not fail the comparison".PHP_EOL;
        }
    }
}

echo PHP_EOL."Reports written to [{$outDir}]. Compare with verdict:evaluation-compare against tests/Baselines;".PHP_EOL;
echo 'after an intentional, reviewed pack change refresh with [composer evaluation:refresh-baselines].'.PHP_EOL;
