<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;

return [
    'approvals' => [
        // Database security-state mutations fail if this connection is already inside an outer
        // transaction. Use a separately committed connection when wrapping Verdict itself.
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

        // Only consulted when 'recorder' is AttestEvidenceRecorder::class. Requires
        // fissible/attest-laravel (composer require fissible/attest-laravel) — see
        // docs/limitations.md, "Tamper-evident evidence is opt-in, partial, and bounded
        // by key custody".
        'attest' => [
            // Fixed chain id used by this default binding. Every deployment writes every
            // decision and context release to this one chain. Multi-tenant applications
            // should instead bind their own EvidenceRecorder — e.g. in a service provider:
            //   $this->app->extend(EvidenceRecorder::class, fn ($default, $app) => new AttestEvidenceRecorder(
            //       ..., chainIdUsing: fn (): string => 'tenant:'.CurrentTenant::id(), ...
            //   ));
            // See docs/limitations.md for the truncation-exposure and key-custody caveats
            // that apply regardless of chain topology.
            'chain' => env('VERDICT_ATTEST_CHAIN', 'verdict'),

            // The non-chained fallback recorder's connection/table. Provenance entries and
            // derivations are always readable through this table; decisions and context
            // releases are not (they exist only in the attest chain, plus a "chain_gap"
            // marker row here if a chained write ever exhausts its retries).
            'fallback_connection' => null,
            'fallback_table' => 'verdict_evidence',

            // Off by default: provenance volume scales with retrieved context, which can be
            // orders of magnitude larger than decisions, and chaining it by default would
            // make throughput unrepresentative of what most deployments need.
            'chain_provenance' => false,

            // 'alert' (default) never blocks the protected action — ADR 0007 already
            // decided evidence is not an authorization gate. 'throw' is for deployments
            // whose compliance regime requires fail-closed on evidence-write failure.
            'on_failure' => 'alert',
            'max_attempts' => 3,
            'base_delay_ms' => 50,
        ],
    ],

    'rate_limits' => [
        // InMemoryRateLimitStore is only for tests and local development. Its process-local
        // counters do not coordinate across requests, workers, or application nodes.
        // Database consumption also requires an independently committed connection.
        'store' => DatabaseRateLimitStore::class,
        'connection' => null,
        'table' => 'verdict_rate_limit_buckets',
    ],

    'execution_claims' => [
        // InMemoryExecutionClaimStore is only for tests and local development. Its process-local
        // state cannot prevent duplicate execution across workers or application nodes.
        // Database claim transitions also require an independently committed connection.
        'store' => DatabaseExecutionClaimStore::class,
        'connection' => null,
        'table' => 'verdict_execution_claims',
    ],

    'ai' => [
        'denied_message' => 'This action was not authorized.',
    ],

    'evaluation' => [
        // Live evaluation makes real provider calls. It requires this configuration opt-in and
        // LiveEvaluationOptions(enabled: true) at the call site.
        'live_enabled' => false,
        'maximum_trials' => 25,
    ],
];
