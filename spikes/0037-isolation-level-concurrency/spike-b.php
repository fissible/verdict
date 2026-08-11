<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';
require __DIR__.'/lib/harness.php';

const CONTENTION_LEVELS = [2, 5, 20];
const RATE_LIMIT = 5;

// See spike-a.php for how this buffer was chosen (measured empirically).
const START_BARRIER_BUFFER_SECONDS = 1.0;

// This spike observes and records rather than grading pass/fail against a normal-contention
// invariant (SERIALIZABLE may legitimately throw under contention — that's expected, not a
// failure). But the underlying invariant must still hold among responses that DON'T throw: never
// more than the configured limit admitted, never more than one claim winner, never more than one
// approval issuer. A violation there — a wrong answer, not an exception — is the one thing this
// script does treat as a hard failure, since it would mean SERIALIZABLE plus Verdict's own code
// produces an incorrect decision, not just an infrastructure exception.
$invariantViolated = false;

foreach (CONTENTION_LEVELS as $concurrency) {
    echo "\n########## contention level: {$concurrency} ##########\n";

    foreach (['rate_limit', 'claim', 'approval'] as $storeKind) {
        $fingerprint = hash('sha256', random_bytes(16));
        $toolCallId = bin2hex(random_bytes(16));
        $at = (new DateTimeImmutable)->format(DATE_ATOM);
        $startAt = microtime(true) + START_BARRIER_BUFFER_SECONDS;

        $payloads = array_fill(0, $concurrency, [
            'connection' => 'postgres_serializable',
            'store' => $storeKind,
            'fingerprint' => $fingerprint,
            'tool_call_id' => $toolCallId,
            'limit' => RATE_LIMIT,
            'window_seconds' => 60,
            'at' => $at,
            'start_at' => $startAt,
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

            if ($admitted > RATE_LIMIT) {
                echo "  INVARIANT VIOLATED: more than the configured limit was admitted.\n";
                $invariantViolated = true;
            }
        } elseif ($storeKind === 'claim') {
            $winners = count(array_filter($succeeded, fn ($d) => $d['admitted'] ?? false));
            printf("  winners: %d (expected at most 1)\n", $winners);

            if ($winners > 1) {
                echo "  INVARIANT VIOLATED: more than one execution-claim winner.\n";
                $invariantViolated = true;
            }
        } else {
            $issuers = count(array_filter($succeeded, fn ($d) => $d['issued'] ?? false));
            printf("  issuers: %d (expected at most 1)\n", $issuers);

            if ($issuers > 1) {
                echo "  INVARIANT VIOLATED: more than one approval-receipt issuer.\n";
                $invariantViolated = true;
            }
        }
    }
}

echo "\n========== SPIKE B: OBSERVATION COMPLETE".($invariantViolated ? ' — INVARIANT VIOLATED, SEE ABOVE' : ' (invariant held among all non-throwing responses)')." ==========\n";

exit($invariantViolated ? 1 : 0);
