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

require __DIR__.'/../vendor/autoload.php';

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$capsule = new Manager;
$capsule->addConnection($payload['connection']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$connection = Manager::connection();
$connection->getPdo();

$ready = fopen('php://fd/3', 'w');
fwrite($ready, '1');
fclose($ready);
$release = fopen('php://fd/4', 'r');
fread($release, 1);
fclose($release);

$at = new DateTimeImmutable($payload['at']);
$startedAt = hrtime(true);

try {
    $winner = match ($payload['store']) {
        'rate_limit' => (new DatabaseRateLimitStore($connection))->consume(new RateLimitConsumption(
            bucketFingerprint: $payload['key'], limit: 100, windowSeconds: 60, at: $at,
        ))->allowed,
        'execution_claim' => (new DatabaseExecutionClaimStore($connection))->claim(new ExecutionClaim(
            id: bin2hex(random_bytes(16)), capability: 'benchmark.claim', policy: 'benchmark',
            bindingFingerprint: $payload['key'], status: ExecutionClaimStatus::Claimed, attemptCount: 1,
            claimedAt: $at, completedAt: null, indeterminateAt: null, releasedAt: null,
            resolvedBy: null, resolutionReason: null, createdAt: $at, updatedAt: $at,
        ))->admitted(),
        'approval_receipt' => (new DatabaseApprovalReceiptStore($connection))->issue(new ApprovalReceipt(
            id: bin2hex(random_bytes(16)), toolCallId: $payload['key'], capability: 'benchmark.approval',
            bindingFingerprint: hash('sha256', $payload['key']), status: ApprovalReceiptStatus::Pending,
            reason: null, expiresAt: $at->modify('+1 hour'), approvedBy: null, approvedAt: null,
            rejectedBy: null, rejectedAt: null, consumedAt: null, createdAt: $at, updatedAt: $at,
        ))->succeeded(),
    };

    fwrite(STDOUT, json_encode([
        'ok' => true, 'winner' => $winner,
        'latency_ms' => (hrtime(true) - $startedAt) / 1_000_000,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode([
        'ok' => false, 'exception' => $error::class,
        'sqlstate' => $error instanceof QueryException ? $error->getCode() : null,
        'latency_ms' => (hrtime(true) - $startedAt) / 1_000_000,
    ], JSON_THROW_ON_ERROR));
}
