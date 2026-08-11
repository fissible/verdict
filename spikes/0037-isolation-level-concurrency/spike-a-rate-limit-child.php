<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

spike_capsule(spike_connections()[$payload['connection']]);

$store = new \Fissible\Verdict\RateLimits\DatabaseRateLimitStore(
    \Illuminate\Database\Capsule\Manager::connection(),
);

$at = new DateTimeImmutable($payload['at']);

try {
    $outcome = $store->consume(new \Fissible\Verdict\RateLimits\RateLimitConsumption(
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
} catch (\Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof \Illuminate\Database\QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
