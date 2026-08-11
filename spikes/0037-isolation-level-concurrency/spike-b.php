<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';
require __DIR__.'/lib/harness.php';

const CONTENTION_LEVELS = [2, 5, 20];
const RATE_LIMIT = 5;

foreach (CONTENTION_LEVELS as $concurrency) {
    echo "\n########## contention level: {$concurrency} ##########\n";

    foreach (['rate_limit', 'claim'] as $storeKind) {
        $fingerprint = hash('sha256', random_bytes(16));
        $at = (new DateTimeImmutable)->format(DATE_ATOM);

        $payloads = array_fill(0, $concurrency, [
            'connection' => 'postgres_serializable',
            'store' => $storeKind,
            'fingerprint' => $fingerprint,
            'limit' => RATE_LIMIT,
            'window_seconds' => 60,
            'at' => $at,
        ]);

        $results = spike_run_concurrent(__DIR__.'/spike-b-child.php', $payloads);
        $decoded = array_map(fn ($r) => json_decode($r['stdout'], true), $results);

        $succeeded = array_filter($decoded, fn ($d) => is_array($d) && ($d['ok'] ?? false));
        $threw = array_filter($decoded, fn ($d) => ! is_array($d) || ! ($d['ok'] ?? false));

        printf(
            "%s: %d/%d succeeded, %d threw\n",
            $storeKind,
            count($succeeded),
            $concurrency,
            count($threw),
        );

        $bySqlstate = [];
        foreach ($threw as $d) {
            $key = is_array($d) ? (($d['exception'] ?? 'unknown').':'.($d['sqlstate'] ?? 'none')) : 'undecodable';
            $bySqlstate[$key] = ($bySqlstate[$key] ?? 0) + 1;
        }
        foreach ($bySqlstate as $key => $count) {
            echo "  thrown: {$key} x{$count}\n";
        }

        if ($storeKind === 'rate_limit') {
            $admitted = count(array_filter($succeeded, fn ($d) => $d['allowed']));
            printf("  admitted: %d (limit %d)\n", $admitted, RATE_LIMIT);
        } else {
            $winners = count(array_filter($succeeded, fn ($d) => $d['admitted'] ?? false));
            printf("  winners: %d (expected at most 1)\n", $winners);
        }
    }
}

echo "\n========== SPIKE B: OBSERVATION COMPLETE (see above for raw findings) ==========\n";
