<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

spike_capsule(spike_connections()[$payload['connection']]);

$connection = \Illuminate\Database\Capsule\Manager::connection();

// Every transaction this connection opens for the rest of the process now runs at SERIALIZABLE.
$connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');

$at = new DateTimeImmutable($payload['at']);

try {
    if ($payload['store'] === 'rate_limit') {
        $store = new \Fissible\Verdict\RateLimits\DatabaseRateLimitStore($connection);

        $outcome = $store->consume(new \Fissible\Verdict\RateLimits\RateLimitConsumption(
            bucketFingerprint: $payload['fingerprint'],
            limit: $payload['limit'],
            windowSeconds: $payload['window_seconds'],
            at: $at,
        ));

        fwrite(STDOUT, json_encode([
            'ok' => true,
            'allowed' => $outcome->allowed,
            'remaining' => $outcome->remaining,
        ], JSON_THROW_ON_ERROR));
    } else {
        $store = new \Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore($connection);

        $transition = $store->claim(new \Fissible\Verdict\ExecutionClaims\ExecutionClaim(
            id: bin2hex(random_bytes(16)),
            capability: 'spike.claim',
            policy: 'spike-policy',
            bindingFingerprint: $payload['fingerprint'],
            status: \Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus::Claimed,
            attemptCount: 1,
            claimedAt: $at,
            completedAt: null,
            indeterminateAt: null,
            releasedAt: null,
            resolvedBy: null,
            resolutionReason: null,
            createdAt: $at,
            updatedAt: $at,
        ));

        fwrite(STDOUT, json_encode([
            'ok' => true,
            'admitted' => $transition->admitted(),
            'outcome' => $transition->outcome->value,
        ], JSON_THROW_ON_ERROR));
    }
} catch (\Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof \Illuminate\Database\QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
