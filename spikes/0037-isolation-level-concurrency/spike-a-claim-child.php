<?php

declare(strict_types=1);
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

spike_capsule(spike_connections()[$payload['connection']]);

$connection = Manager::connection();

// Force the lazy PDO connection to actually establish now, before the barrier —
// Connection::$pdo starts as a Closure and is only resolved on first use (getPdo()), so without
// this, the barrier releases processes that then each independently pay a TCP handshake + auth
// round-trip right after, silently reintroducing the unsynchronized-startup variance the barrier
// exists to eliminate.
$connection->getPdo();

$store = new DatabaseExecutionClaimStore($connection);

$at = new DateTimeImmutable($payload['at']);

// Start barrier — see spike-a-rate-limit-child.php for the rationale and measured buffer.
while (microtime(true) < $payload['start_at']) {
    usleep(200);
}

try {
    $transition = $store->claim(new ExecutionClaim(
        id: bin2hex(random_bytes(16)),
        capability: 'spike.claim',
        policy: 'spike-policy',
        bindingFingerprint: $payload['binding_fingerprint'],
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
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
