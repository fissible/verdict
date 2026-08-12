<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;

require __DIR__.'/../../../vendor/autoload.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$capsule = new Manager;
$capsule->addConnection($payload['connection']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$connection = Manager::connection();

// Force the lazy PDO connection before the barrier, so every contender is ready to execute its
// transition when the parent releases the batch.
$connection->getPdo();

if ($payload['force_serializable'] ?? false) {
    $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
}

$store = new DatabaseApprovalReceiptStore($connection);
$at = new DateTimeImmutable($payload['at']);

$ready = fopen('php://fd/3', 'w');
fwrite($ready, '1');
fclose($ready);

$release = fopen('php://fd/4', 'r');
fread($release, 1);
fclose($release);

try {
    $transition = match ($payload['action']) {
        'approve' => $store->approve($payload['receipt_id'], $payload['tool_call_id'], $payload['decided_by'], $at),
        'reject' => $store->reject($payload['receipt_id'], $payload['tool_call_id'], $payload['decided_by'], $at),
        'consume' => $store->consume($payload['tool_call_id'], $payload['binding_fingerprint'], $at),
    };

    fwrite(STDOUT, json_encode([
        'ok' => true,
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
