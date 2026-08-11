<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

spike_capsule(spike_connections()[$payload['connection']]);

$store = new \Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore(
    \Illuminate\Database\Capsule\Manager::connection(),
);

$at = new DateTimeImmutable($payload['at']);

try {
    $transition = $store->claim(new \Fissible\Verdict\ExecutionClaims\ExecutionClaim(
        id: bin2hex(random_bytes(16)),
        capability: 'spike.claim',
        policy: 'spike-policy',
        bindingFingerprint: $payload['binding_fingerprint'],
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
} catch (\Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof \Illuminate\Database\QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
