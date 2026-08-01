<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
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

    'ai' => [
        'denied_message' => 'This action was not authorized.',
    ],
];
