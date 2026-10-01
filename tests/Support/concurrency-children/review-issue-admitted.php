<?php

declare(strict_types=1);

use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;

require __DIR__.'/../../../vendor/autoload.php';

// #544 concurrency worker. Two of these race issueAdmitted() for the SAME (capability,
// binding_fingerprint) with DISTINCT ids, over SEPARATE connections. The "holder" (hold=true) signals
// once it is INSIDE its admission hook — lock acquired, before the insert — and then blocks until
// released, so the observer can prove the contender either (bug) runs its own hook or (fixed) blocks on
// the holder's admission lock. fd 3 = report channel to the parent; fd 4 = release barrier (holder only).
$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$capsule = new Manager;
$capsule->addConnection($payload['connection']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

Container::getInstance()->instance('config', new Repository([
    'verdict' => ['approvals' => ['binding_admission_locks_table' => 'verdict_binding_admission_locks']],
]));

$connection = Manager::connection();
$connection->getPdo(); // force the lazy PDO now, before any barrier

$report = fopen('php://fd/3', 'w');
$backendPid = (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;
fwrite($report, json_encode(['event' => 'pid', 'pid' => $backendPid])."\n");

$store = new DatabaseReviewRequestStore($connection);
$at = new DateTimeImmutable($payload['at']);

$hookRan = false;
$onAdmitted = function () use ($payload, $report, $backendPid, &$hookRan): void {
    $hookRan = true;
    // Reaching here is the attest point. The holder announces it is in the critical section and holds;
    // the contender announces it too — and for a correct fix the contender must NEVER reach this.
    fwrite($report, json_encode(['event' => 'hook', 'pid' => $backendPid])."\n");

    if ($payload['hold'] ?? false) {
        $release = fopen('php://fd/4', 'r');
        fread($release, 1); // block until the parent releases us (EOF on close)
        fclose($release);
    }
};

try {
    $transition = $store->issueAdmitted(new ReviewRequest(
        id: $payload['id'],
        capability: $payload['capability'],
        bindingFingerprint: $payload['binding_fingerprint'],
        approvalContext: ['k' => 'v'],
        provenance: null,
        approverSummary: null,
        status: ReviewStatus::Pending,
        reason: null,
        createdAt: $at,
        expiresAt: $at->modify('+1 hour'),
        resolvedBy: null,
        resolvedAt: null,
        consumedAt: null,
    ), $onAdmitted);

    fwrite($report, json_encode([
        'event' => 'done', 'ok' => true, 'outcome' => $transition->outcome->value,
        'resolved_id' => $transition->request?->id, 'hook_ran' => $hookRan,
    ])."\n");
} catch (Throwable $e) {
    fwrite($report, json_encode(['event' => 'done', 'ok' => false, 'exception' => $e::class, 'message' => $e->getMessage(), 'hook_ran' => $hookRan])."\n");
}
fclose($report);
