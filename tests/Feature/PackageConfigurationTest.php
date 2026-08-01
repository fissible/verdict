<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Support\ServiceProvider;

it('publishes the durable approval receipt migration', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-migrations',
    );

    expect($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('create_verdict_approval_receipts_table.php.stub')
        ->and(array_values($migrations)[0])->toEndWith('create_verdict_approval_receipts_table.php');
});

it('resolves the configured database receipt store', function (): void {
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    $this->app->forgetInstance(ApprovalReceiptStore::class);

    expect(app(ApprovalReceiptStore::class))->toBeInstanceOf(DatabaseApprovalReceiptStore::class);
});

it('ships database-backed approval receipt defaults', function (): void {
    /** @var array<string, mixed> $defaults */
    $defaults = require __DIR__.'/../../config/verdict.php';

    expect($defaults['approvals']['store'])->toBe(DatabaseApprovalReceiptStore::class)
        ->and($defaults['approvals']['table'])->toBe('verdict_approval_receipts')
        ->and($defaults['approvals']['ttl_seconds'])->toBe(900);
});
