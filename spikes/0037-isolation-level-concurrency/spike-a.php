<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';
require __DIR__.'/lib/harness.php';

const CONCURRENCY = 20;
const RATE_LIMIT = 5;

const DRIVERS_UNDER_TEST = ['postgres', 'mysql_repeatable_read', 'mysql_read_committed', 'mariadb'];

$overallPass = true;

foreach (DRIVERS_UNDER_TEST as $connectionName) {
    echo "\n########## {$connectionName} ##########\n";

    // --- Rate limit race ---
    // CHAR(64) column — use a real sha256 hex digest (64 chars), matching how production actually
    // generates fingerprints, so fixed-length CHAR padding behavior can't differ across drivers and
    // contaminate the comparison this spike exists to make.
    $bucketFingerprint = hash('sha256', random_bytes(16));
    $at = (new DateTimeImmutable)->format(DATE_ATOM);

    $payloads = array_fill(0, CONCURRENCY, [
        'connection' => $connectionName,
        'bucket_fingerprint' => $bucketFingerprint,
        'limit' => RATE_LIMIT,
        'window_seconds' => 60,
        'at' => $at,
    ]);

    $results = spike_run_concurrent(__DIR__.'/spike-a-rate-limit-child.php', $payloads);

    $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);
    $admitted = count(array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && $d['allowed']));
    $errors = array_filter($decoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false));

    $ratePass = $admitted === RATE_LIMIT && $errors === [];

    printf(
        "rate-limit: %d/%d admitted (expected %d), %d errors -> %s\n",
        $admitted,
        CONCURRENCY,
        RATE_LIMIT,
        count($errors),
        $ratePass ? 'PASS' : 'FAIL',
    );

    foreach ($errors as $e) {
        echo '  error: '.json_encode($e)."\n";
    }

    // --- Execution claim race ---
    $bindingFingerprint = hash('sha256', random_bytes(16));

    $claimPayloads = array_fill(0, CONCURRENCY, [
        'connection' => $connectionName,
        'binding_fingerprint' => $bindingFingerprint,
        'at' => $at,
    ]);

    $claimResults = spike_run_concurrent(__DIR__.'/spike-a-claim-child.php', $claimPayloads);
    $claimDecoded = array_map(fn ($r) => json_decode($r['stdout'], true), $claimResults);
    $winners = count(array_filter($claimDecoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['admitted'] ?? false)));
    $claimErrors = array_filter($claimDecoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false));

    $claimPass = $winners === 1 && $claimErrors === [];

    printf(
        "execution-claim: %d/%d winners (expected 1), %d errors -> %s\n",
        $winners,
        CONCURRENCY,
        count($claimErrors),
        $claimPass ? 'PASS' : 'FAIL',
    );

    foreach ($claimErrors as $e) {
        echo '  error: '.json_encode($e)."\n";
    }

    $overallPass = $overallPass && $ratePass && $claimPass;
}

echo "\n========== SPIKE A: ".($overallPass ? 'ALL DRIVERS PASS' : 'AT LEAST ONE FAILURE')." ==========\n";
