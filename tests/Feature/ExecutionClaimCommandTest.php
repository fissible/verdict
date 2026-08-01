<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;

function commandExecutionClaim(): ExecutionClaim
{
    $now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    return new ExecutionClaim(
        id: 'claim-for-reconciliation',
        capability: 'orders.cancel',
        policy: 'cancel-order-version',
        bindingFingerprint: hash('sha256', 'order-1002-version-4'),
        status: ExecutionClaimStatus::Claimed,
        attemptCount: 1,
        claimedAt: $now,
        completedAt: null,
        indeterminateAt: null,
        releasedAt: null,
        resolvedBy: null,
        resolutionReason: null,
        createdAt: $now,
        updatedAt: $now,
    );
}

it('lists unresolved claims without exposing their canonical binding', function (): void {
    app(ExecutionClaimStore::class)->claim(commandExecutionClaim());

    $this->artisan('verdict:execution-claims')
        ->expectsTable(
            ['Claim ID', 'Capability', 'Policy', 'Status', 'Attempts', 'Updated'],
            [[
                'claim-for-reconciliation',
                'orders.cancel',
                'cancel-order-version',
                'claimed',
                1,
                '2026-08-01T12:00:00+00:00',
            ]],
        )
        ->assertSuccessful();
});

it('requires explicit force and operator evidence to release an active claim', function (): void {
    $store = app(ExecutionClaimStore::class);
    $store->claim(commandExecutionClaim());

    $this->artisan('verdict:resolve-execution-claim', [
        'claim' => 'claim-for-reconciliation',
        'resolution' => 'retryable',
        '--by' => 'operator:7',
        '--reason' => 'Carrier confirmed no request was accepted.',
    ])->assertFailed();

    $this->artisan('verdict:resolve-execution-claim', [
        'claim' => 'claim-for-reconciliation',
        'resolution' => 'retryable',
        '--by' => 'operator:7',
        '--reason' => 'Carrier confirmed no request was accepted.',
        '--force' => true,
    ])->expectsOutputToContain('released for one explicit retry')->assertSuccessful();

    expect($store->find('claim-for-reconciliation')?->status)->toBe(ExecutionClaimStatus::Released)
        ->and($store->find('claim-for-reconciliation')?->resolvedBy)->toBe('operator:7');
});
