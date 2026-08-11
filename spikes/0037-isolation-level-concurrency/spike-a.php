<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';
require __DIR__.'/lib/harness.php';

const CONCURRENCY = 20;
const RATE_LIMIT = 5;

// Buffer for the start barrier: real boot-to-first-query latency measured empirically under
// 20-way parallel process startup was 52-175ms (p95); 1000ms is ~6x that p95, a generous margin
// so every child is already connected and parked at the barrier well before it releases.
const START_BARRIER_BUFFER_SECONDS = 1.0;

// Excludes postgres_serializable deliberately: SERIALIZABLE may legitimately raise 40001 under
// contention, which would register as a false FAIL against this loop's normal-contention invariant.
// That's Spike B's job specifically (Task 5).
const DRIVERS_UNDER_TEST = ['postgres', 'mysql_repeatable_read', 'mysql_read_committed', 'mariadb'];

$overallPass = true;

foreach (DRIVERS_UNDER_TEST as $connectionName) {
    echo "\n########## {$connectionName} ##########\n";

    $at = (new DateTimeImmutable)->format(DATE_ATOM);
    $startAt = microtime(true) + START_BARRIER_BUFFER_SECONDS;

    // --- Rate limit race ---
    // CHAR(64) column — use a real sha256 hex digest (64 chars), matching how production actually
    // generates fingerprints, so fixed-length CHAR padding behavior can't differ across drivers and
    // contaminate the comparison this spike exists to make.
    $bucketFingerprint = hash('sha256', random_bytes(16));

    $payloads = array_fill(0, CONCURRENCY, [
        'connection' => $connectionName,
        'bucket_fingerprint' => $bucketFingerprint,
        'limit' => RATE_LIMIT,
        'window_seconds' => 60,
        'at' => $at,
        'start_at' => $startAt,
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
    $startAt = microtime(true) + START_BARRIER_BUFFER_SECONDS;

    $claimPayloads = array_fill(0, CONCURRENCY, [
        'connection' => $connectionName,
        'binding_fingerprint' => $bindingFingerprint,
        'at' => $at,
        'start_at' => $startAt,
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

    // --- Approval receipt race ---
    $bindingFingerprint = hash('sha256', random_bytes(16));
    $toolCallId = bin2hex(random_bytes(16));
    $startAt = microtime(true) + START_BARRIER_BUFFER_SECONDS;

    $approvalPayloads = array_fill(0, CONCURRENCY, [
        'connection' => $connectionName,
        'binding_fingerprint' => $bindingFingerprint,
        'tool_call_id' => $toolCallId,
        'at' => $at,
        'start_at' => $startAt,
    ]);

    $approvalResults = spike_run_concurrent(__DIR__.'/spike-a-approval-child.php', $approvalPayloads);
    $approvalDecoded = array_map(fn ($r) => json_decode($r['stdout'], true), $approvalResults);
    $issuers = count(array_filter($approvalDecoded, fn ($d) => is_array($d) && ($d['ok'] ?? false) && ($d['issued'] ?? false)));
    $approvalErrors = array_filter($approvalDecoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false));

    $approvalPass = $issuers === 1 && $approvalErrors === [];

    printf(
        "approval-receipt: %d/%d issuers (expected 1), %d errors -> %s\n",
        $issuers,
        CONCURRENCY,
        count($approvalErrors),
        $approvalPass ? 'PASS' : 'FAIL',
    );

    foreach ($approvalErrors as $e) {
        echo '  error: '.json_encode($e)."\n";
    }

    $overallPass = $overallPass && $ratePass && $claimPass && $approvalPass;
}

echo "\n========== SPIKE A: ".($overallPass ? 'ALL DRIVERS PASS' : 'AT LEAST ONE FAILURE')." ==========\n";

exit($overallPass ? 0 : 1);
