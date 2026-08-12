<?php

declare(strict_types=1);

use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimResolution;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;

require __DIR__.'/../../../vendor/autoload.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$capsule = new Manager;
$capsule->addConnection($payload['connection']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$connection = Manager::connection();
$connection->getPdo();

if ($payload['force_serializable'] ?? false) {
    $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
}

$store = new DatabaseExecutionClaimStore($connection);
$at = new DateTimeImmutable($payload['at']);

$ready = fopen('php://fd/3', 'w');
fwrite($ready, '1');
fclose($ready);

$release = fopen('php://fd/4', 'r');
fread($release, 1);
fclose($release);

try {
    $transition = match ($payload['action']) {
        'complete' => $store->complete($payload['claim_id'], $at),
        'mark_indeterminate' => $store->markIndeterminate($payload['claim_id'], $at),
        'resolve_retryable' => $store->resolve(
            $payload['claim_id'],
            ExecutionClaimResolution::Retryable,
            'concurrency-test:resolver',
            'retry after concurrency test',
            $at,
        ),
    };

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'outcome' => $transition->outcome->value,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $error::class,
        'sqlstate' => $error instanceof QueryException ? $error->getCode() : null,
        'message' => $error->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
