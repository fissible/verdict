<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
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

// Start barrier — see spike-a-rate-limit-child.php for the rationale and measured buffer.
while (microtime(true) < $payload['start_at']) {
    usleep(200);
}

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
    } elseif ($payload['store'] === 'claim') {
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
    } else {
        $store = new DatabaseApprovalReceiptStore($connection);

        $transition = $store->issue(new ApprovalReceipt(
            id: bin2hex(random_bytes(16)),
            toolCallId: $payload['tool_call_id'],
            capability: 'spike.approval',
            bindingFingerprint: $payload['fingerprint'],
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
