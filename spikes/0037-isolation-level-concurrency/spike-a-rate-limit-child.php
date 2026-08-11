<?php

declare(strict_types=1);
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitConsumption;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

spike_capsule(spike_connections()[$payload['connection']]);

$store = new DatabaseRateLimitStore(
    Manager::connection(),
);

$at = new DateTimeImmutable($payload['at']);

// Start barrier: every child in this batch is given the same wall-clock deadline, computed by the
// orchestrator with a generous buffer (measured empirically — real boot-to-first-query latency
// under 20-way parallel startup is ~52-175ms; the buffer is ~6x the observed p95). Without this,
// children reach the store call at wildly different times (process boot, composer autoload, DB
// handshake are the dominant variance, not the query itself), so "zero errors" would not actually
// prove the invariant holds under real overlapping contention.
while (microtime(true) < $payload['start_at']) {
    usleep(200);
}

try {
    $outcome = $store->consume(new RateLimitConsumption(
        bucketFingerprint: $payload['bucket_fingerprint'],
        limit: $payload['limit'],
        windowSeconds: $payload['window_seconds'],
        at: $at,
    ));

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'allowed' => $outcome->allowed,
        'remaining' => $outcome->remaining,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
