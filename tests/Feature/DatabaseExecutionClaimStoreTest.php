<?php

declare(strict_types=1);

use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimOutcome;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimResolution;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('execution_claims'));
    $schema->create(verdictTable('execution_claims'), function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('capability');
        $table->string('policy');
        $table->char('binding_fingerprint', 64)->unique();
        $table->string('status', 24);
        $table->unsignedInteger('attempt_count');
        $table->timestamp('claimed_at');
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('indeterminate_at')->nullable();
        $table->timestamp('released_at')->nullable();
        $table->string('resolved_by')->nullable();
        $table->text('resolution_reason')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('execution_claims'));
});

function databaseExecutionClaimStore(): DatabaseExecutionClaimStore
{
    return new DatabaseExecutionClaimStore(app(DatabaseManager::class)->connection());
}

function databaseExecutionClaim(string $id = 'claim-1', string $binding = 'logical-operation'): ExecutionClaim
{
    $now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));

    return new ExecutionClaim(
        id: $id,
        capability: 'orders.cancel',
        policy: 'cancel-order-version',
        bindingFingerprint: hash('sha256', $binding),
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

it('atomically admits one claim and rejects its completed duplicate', function (): void {
    $store = databaseExecutionClaimStore();
    $claim = databaseExecutionClaim();

    expect($store->claim($claim)->outcome)->toBe(ExecutionClaimOutcome::Claimed)
        ->and($store->complete($claim->id, new DateTimeImmutable('2026-08-01 12:00:01'))->outcome)
        ->toBe(ExecutionClaimOutcome::Completed)
        ->and($store->claim(databaseExecutionClaim('claim-2'))->outcome)
        ->toBe(ExecutionClaimOutcome::DuplicateCompleted)
        ->and(app(DatabaseManager::class)->connection()->table(verdictTable('execution_claims'))->count())
        ->toBe(1);
});

it('keeps indeterminate claims blocked until explicit reconciliation', function (): void {
    $store = databaseExecutionClaimStore();
    $claim = databaseExecutionClaim();
    $store->claim($claim);
    $store->markIndeterminate($claim->id, new DateTimeImmutable('2026-08-01 12:00:01'));

    expect($store->claim(databaseExecutionClaim('claim-2'))->outcome)
        ->toBe(ExecutionClaimOutcome::DuplicateIndeterminate)
        ->and($store->unresolved())->toHaveCount(1);

    $released = $store->resolve(
        claimId: $claim->id,
        resolution: ExecutionClaimResolution::Retryable,
        resolvedBy: 'operator:7',
        reason: 'Carrier confirmed no cancellation was accepted.',
        at: new DateTimeImmutable('2026-08-01 12:05:00'),
    );
    $retried = $store->claim(databaseExecutionClaim('ignored-new-id'));

    expect($released->outcome)->toBe(ExecutionClaimOutcome::Released)
        ->and($released->claim?->resolvedBy)->toBe('operator:7')
        ->and($retried->outcome)->toBe(ExecutionClaimOutcome::Claimed)
        ->and($retried->claim?->id)->toBe($claim->id)
        ->and($retried->claim?->attemptCount)->toBe(2);
});

it('can reconcile an ambiguous claim as completed without permitting retry', function (): void {
    $store = databaseExecutionClaimStore();
    $claim = databaseExecutionClaim();
    $store->claim($claim);
    $store->markIndeterminate($claim->id, new DateTimeImmutable('2026-08-01 12:00:01'));

    $resolved = $store->resolve(
        $claim->id,
        ExecutionClaimResolution::Completed,
        'operator:7',
        'Carrier confirmed cancellation succeeded.',
        new DateTimeImmutable('2026-08-01 12:05:00'),
    );

    expect($resolved->outcome)->toBe(ExecutionClaimOutcome::Completed)
        ->and($store->claim(databaseExecutionClaim('claim-2'))->outcome)
        ->toBe(ExecutionClaimOutcome::DuplicateCompleted)
        ->and($store->unresolved())->toBe([]);
});

it('rejects claim mutations inside an outer transaction on the store connection', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $store = new DatabaseExecutionClaimStore($connection);

    $connection->beginTransaction();

    try {
        expect(fn () => $store->claim(databaseExecutionClaim()))
            ->toThrow(UnsafeOuterTransaction::class, 'claim an execution');
    } finally {
        $connection->rollBack();
    }

    expect($connection->transactionLevel())->toBe(0)
        ->and($connection->table(verdictTable('execution_claims'))->count())->toBe(0);
});

it('clears stale resolution metadata when a released claim is re-claimed (#355)', function (): void {
    $store = databaseExecutionClaimStore();
    $claim = databaseExecutionClaim();

    expect($store->claim($claim)->outcome)->toBe(ExecutionClaimOutcome::Claimed);

    // Release it with operator attribution, exactly as a reconciliation resolve does.
    $released = $store->resolve(
        $claim->id,
        ExecutionClaimResolution::Retryable,
        'operator-x',
        'released for retry',
        new DateTimeImmutable('2026-08-01 12:00:05', new DateTimeZone('UTC')),
    );

    expect($released->outcome)->toBe(ExecutionClaimOutcome::Released)
        ->and($released->claim?->releasedAt)->not->toBeNull()
        ->and($released->claim?->resolvedBy)->toBe('operator-x')
        ->and($released->claim?->resolutionReason)->toBe('released for retry');

    // Re-claim the same binding — the retry path claimExisting() exists for.
    $reclaimed = $store->claim(new ExecutionClaim(
        id: $claim->id,
        capability: $claim->capability,
        policy: $claim->policy,
        bindingFingerprint: $claim->bindingFingerprint,
        status: ExecutionClaimStatus::Claimed,
        attemptCount: 1,
        claimedAt: new DateTimeImmutable('2026-08-01 12:00:10', new DateTimeZone('UTC')),
        completedAt: null,
        indeterminateAt: null,
        releasedAt: null,
        resolvedBy: null,
        resolutionReason: null,
        createdAt: $claim->createdAt,
        updatedAt: $claim->createdAt,
    ));

    expect($reclaimed->outcome)->toBe(ExecutionClaimOutcome::Claimed);

    // The reloaded claim is actively Claimed again — its audit trail must not still say it was
    // "released at T by operator-x". Stale released_at / resolved_by / resolution_reason is the bug.
    $row = $store->find($claim->id);

    expect($row?->status)->toBe(ExecutionClaimStatus::Claimed)
        ->and($row?->attemptCount)->toBe(2)
        ->and($row?->releasedAt)->toBeNull()
        ->and($row?->resolvedBy)->toBeNull()
        ->and($row?->resolutionReason)->toBeNull();
});
