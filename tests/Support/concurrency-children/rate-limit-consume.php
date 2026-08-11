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

// Force the lazy PDO connection to actually establish now, before the barrier. Connection::$pdo
// starts as a Closure and is only resolved on first use (Connection::getPdo()); without this, the
// barrier below would release genuinely-simultaneous processes into a TCP handshake + auth
// round-trip each pays for independently right after, silently reintroducing the same
// unsynchronized-startup variance the barrier exists to eliminate.
$connection->getPdo();

if ($payload['force_serializable'] ?? false) {
    $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
}

$store = new DatabaseRateLimitStore($connection);

$at = new DateTimeImmutable($payload['at']);

// Ready/release handshake: signal readiness on fd 3 (written right after the PDO connection above
// was forced), then block reading fd 4 until the parent has received a readiness signal from every
// child in this batch and releases them all together (by closing its write end of fd 4, which
// unblocks this read with EOF). This replaces a fixed wall-clock buffer — which could only ever be
// a statistical guess at worst-case boot/connect latency, and would silently understate contention
// (a false-clean result) for any child slower than the guess — with a real handshake: nothing here
// proceeds until every child has proven, not assumed, that it is actually ready. See
// ConcurrencyHarness::releaseWhenAllReady() for the parent side.
$ready = fopen('php://fd/3', 'w');
fwrite($ready, '1');
fclose($ready);

$release = fopen('php://fd/4', 'r');
fread($release, 1);
fclose($release);

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
