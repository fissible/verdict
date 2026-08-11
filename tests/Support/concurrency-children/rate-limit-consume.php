<?php

declare(strict_types=1);
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitConsumption;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;

require __DIR__.'/../../../vendor/autoload.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$capsule = new Manager;
$capsule->addConnection($payload['connection']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$connection = Manager::connection();

if ($payload['force_serializable'] ?? false) {
    $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
}

$store = new DatabaseRateLimitStore($connection);

$at = new DateTimeImmutable($payload['at']);

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
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
