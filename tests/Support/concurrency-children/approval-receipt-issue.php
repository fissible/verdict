<?php

declare(strict_types=1);
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
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

if ($payload['force_serializable'] ?? false) {
    $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
}

$store = new DatabaseApprovalReceiptStore($connection);

$at = new DateTimeImmutable($payload['at']);

while (microtime(true) < $payload['start_at']) {
    usleep(200);
}

try {
    $transition = $store->issue(new ApprovalReceipt(
        id: bin2hex(random_bytes(16)),
        toolCallId: $payload['tool_call_id'],
        capability: 'concurrency-test.approval',
        bindingFingerprint: $payload['binding_fingerprint'],
        status: ApprovalReceiptStatus::Pending,
        reason: null,
        expiresAt: $at->modify('+1 hour'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $at,
        updatedAt: $at,
    ));

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'issued' => $transition->outcome->value === 'issued',
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'exception' => $e::class,
        'sqlstate' => $e instanceof QueryException ? $e->getCode() : null,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
