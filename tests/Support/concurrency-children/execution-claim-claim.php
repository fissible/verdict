<?php

declare(strict_types=1);
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;

require __DIR__.'/../../../vendor/autoload.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$capsule = new Manager;
$capsule->addConnection($payload['connection']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$connection = Manager::connection();

// Force the lazy PDO connection to actually establish now, before the barrier — see
// rate-limit-consume.php for why this matters.
$connection->getPdo();

if ($payload['force_serializable'] ?? false) {
    $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
}

$store = new DatabaseExecutionClaimStore($connection);

$at = new DateTimeImmutable($payload['at']);

// Ready/release handshake — see rate-limit-consume.php for why this matters.
$ready = fopen('php://fd/3', 'w');
fwrite($ready, '1');
fclose($ready);

$release = fopen('php://fd/4', 'r');
fread($release, 1);
fclose($release);

try {
    $transition = $store->claim(new ExecutionClaim(
        id: bin2hex(random_bytes(16)),
        capability: 'concurrency-test.claim',
        policy: 'concurrency-test-policy',
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
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
