<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/lib/connections.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

spike_capsule(spike_connections()[$payload['connection']]);

$connection = Manager::connection();

// Force the lazy PDO connection to actually establish now, before the barrier — see
// spike-a-claim-child.php for why this matters.
$connection->getPdo();

$store = new DatabaseApprovalReceiptStore($connection);

$at = new DateTimeImmutable($payload['at']);
$expiresAt = $at->modify('+1 hour');

// Start barrier — see spike-a-rate-limit-child.php for the rationale and measured buffer.
while (microtime(true) < $payload['start_at']) {
    usleep(200);
}

try {
    $transition = $store->issue(new ApprovalReceipt(
        id: bin2hex(random_bytes(16)),
        toolCallId: $payload['tool_call_id'],
        capability: 'spike.approval',
        bindingFingerprint: $payload['binding_fingerprint'],
        status: ApprovalReceiptStatus::Pending,
        reason: null,
        expiresAt: $expiresAt,
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
