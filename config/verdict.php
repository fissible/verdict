<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;

return [
    'approvals' => [
        'store' => DatabaseApprovalReceiptStore::class,
        'connection' => null,
        'table' => 'verdict_approval_receipts',
        'ttl_seconds' => 900,
    ],

    'evidence' => [
        // InMemoryEvidenceRecorder is only for tests and local development. Its unbounded,
        // process-local state is unsafe for production, Octane, and queue workers.
        'recorder' => NullEvidenceRecorder::class,
        'connection' => null,
        'table' => 'verdict_evidence',
    ],

    'rate_limits' => [
        // InMemoryRateLimitStore is only for tests and local development. Its process-local
        // counters do not coordinate across requests, workers, or application nodes.
        'store' => DatabaseRateLimitStore::class,
        'connection' => null,
        'table' => 'verdict_rate_limit_buckets',
    ],

    'execution_claims' => [
        // InMemoryExecutionClaimStore is only for tests and local development. Its process-local
        // state cannot prevent duplicate execution across workers or application nodes.
        'store' => DatabaseExecutionClaimStore::class,
        'connection' => null,
        'table' => 'verdict_execution_claims',
    ],

    'ai' => [
        'denied_message' => 'This action was not authorized.',
    ],
];
