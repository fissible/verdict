<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Support\ServiceProvider;

it('publishes the durable approval receipt migration', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-migrations',
    );

    expect($migrations)->toHaveCount(4)
        ->and(array_keys($migrations))->each->toEndWith('.php.stub')
        ->and(array_values($migrations))->each->toEndWith('.php');
});

it('publishes the durable evidence migration independently', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-evidence-migrations',
    );

    expect($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('create_verdict_evidence_table.php.stub')
        ->and(array_values($migrations)[0])->toEndWith('create_verdict_evidence_table.php');
});

it('resolves the configured database receipt store', function (): void {
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    $this->app->forgetInstance(ApprovalReceiptStore::class);

    expect(app(ApprovalReceiptStore::class))->toBeInstanceOf(DatabaseApprovalReceiptStore::class);
});

it('resolves the configured database evidence recorder', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    $this->app->forgetInstance(EvidenceRecorder::class);

    expect(app(EvidenceRecorder::class))->toBeInstanceOf(DatabaseEvidenceRecorder::class);
});

it('publishes and resolves the database rate-limit store', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-rate-limit-migrations',
    );

    config()->set('verdict.rate_limits.store', DatabaseRateLimitStore::class);
    $this->app->forgetInstance(RateLimitStore::class);

    expect($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('create_verdict_rate_limit_buckets_table.php.stub')
        ->and(app(RateLimitStore::class))->toBeInstanceOf(DatabaseRateLimitStore::class);
});

it('publishes and resolves the database execution-claim store', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-execution-claim-migrations',
    );

    config()->set('verdict.execution_claims.store', DatabaseExecutionClaimStore::class);
    $this->app->forgetInstance(ExecutionClaimStore::class);

    expect($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('create_verdict_execution_claims_table.php.stub')
        ->and(app(ExecutionClaimStore::class))->toBeInstanceOf(DatabaseExecutionClaimStore::class);
});

it('registers the expired rate-limit bucket pruning command', function (): void {
    $this->artisan('verdict:prune-rate-limits')
        ->expectsOutputToContain('Pruned 0 expired Verdict rate-limit bucket(s).')
        ->assertSuccessful();
});

it('ships database-backed approval receipt defaults', function (): void {
    /** @var array<string, mixed> $defaults */
    $defaults = require __DIR__.'/../../config/verdict.php';

    expect($defaults['approvals']['store'])->toBe(DatabaseApprovalReceiptStore::class)
        ->and($defaults['approvals']['table'])->toBe('verdict_approval_receipts')
        ->and($defaults['approvals']['ttl_seconds'])->toBe(900)
        ->and($defaults['evidence']['table'])->toBe('verdict_evidence')
        ->and($defaults['evidence']['connection'])->toBeNull()
        ->and($defaults['rate_limits']['store'])->toBe(DatabaseRateLimitStore::class)
        ->and($defaults['rate_limits']['table'])->toBe('verdict_rate_limit_buckets')
        ->and($defaults['rate_limits']['connection'])->toBeNull()
        ->and($defaults['execution_claims']['store'])->toBe(DatabaseExecutionClaimStore::class)
        ->and($defaults['execution_claims']['table'])->toBe('verdict_execution_claims')
        ->and($defaults['execution_claims']['connection'])->toBeNull();
});
