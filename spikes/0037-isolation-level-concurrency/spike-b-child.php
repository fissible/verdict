<?php

declare(strict_types=1);
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitConsumption;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

spike_capsule(spike_connections()[$payload['connection']]);

$connection = Manager::connection();

// Every transaction this connection opens for the rest of the process now runs at SERIALIZABLE.
$connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');

$at = new DateTimeImmutable($payload['at']);

try {
    if ($payload['store'] === 'rate_limit') {
        $store = new DatabaseRateLimitStore($connection);

        $outcome = $store->consume(new RateLimitConsumption(
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
        $store = new DatabaseExecutionClaimStore($connection);

        $transition = $store->claim(new ExecutionClaim(
            id: bin2hex(random_bytes(16)),
            capability: 'spike.claim',
            policy: 'spike-policy',
            bindingFingerprint: $payload['fingerprint'],
            status: ExecutionClaimStatus::Claimed,
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
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
