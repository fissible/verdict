<?php

declare(strict_types=1);

use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitConsumption;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('rate_limits'));
    $schema->create(verdictTable('rate_limits'), function (Blueprint $table): void {
        $table->char('bucket_fingerprint', 64);
        $table->timestamp('window_starts_at');
        $table->timestamp('reset_at');
        $table->unsignedInteger('attempts');
        $table->timestamps();
        // Named explicitly, matching create_verdict_rate_limit_buckets_table.php.stub: Laravel's
        // auto-generated name for this pair (verdict_rate_limit_buckets_bucket_fingerprint_window_starts_at_unique,
        // 72 chars) exceeds MySQL's 64-character identifier limit (SQLSTATE 42000, error 1059) —
        // silently fine on SQLite/PostgreSQL, which is why this only surfaced under real MySQL.
        $table->unique(['bucket_fingerprint', 'window_starts_at'], 'verdict_rate_limit_bucket_window_unique');
        $table->index('reset_at');
    });
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('rate_limits'));
});

function databaseRateLimitStore(): DatabaseRateLimitStore
{
    return new DatabaseRateLimitStore(app(DatabaseManager::class)->connection());
}

function databaseRateLimitConsumption(string $bucket, string $at): RateLimitConsumption
{
    return new RateLimitConsumption(
        bucketFingerprint: hash('sha256', $bucket),
        limit: 2,
        windowSeconds: 60,
        at: new DateTimeImmutable($at, new DateTimeZone('UTC')),
    );
}

it('atomically counts attempts within a fixed window', function (): void {
    $store = databaseRateLimitStore();

    $first = $store->consume(databaseRateLimitConsumption('customer-72', '2026-08-01 12:00:15'));
    $second = $store->consume(databaseRateLimitConsumption('customer-72', '2026-08-01 12:00:45'));
    $third = $store->consume(databaseRateLimitConsumption('customer-72', '2026-08-01 12:00:59'));

    expect($first->allowed)->toBeTrue()
        ->and($first->remaining)->toBe(1)
        ->and($second->allowed)->toBeTrue()
        ->and($second->remaining)->toBe(0)
        ->and($third->allowed)->toBeFalse()
        ->and($third->remaining)->toBe(0)
        ->and($third->resetAt->getTimestamp())->toBe(
            (new DateTimeImmutable('2026-08-01 12:01:00', new DateTimeZone('UTC')))->getTimestamp(),
        )
        ->and(app(DatabaseManager::class)->connection()->table(verdictTable('rate_limits'))->value('attempts'))
        ->toBe(2);
});

it('starts a fresh bucket at the exact window boundary and prunes expired buckets', function (): void {
    $store = databaseRateLimitStore();
    $store->consume(databaseRateLimitConsumption('customer-72', '2026-08-01 12:00:59'));
    $next = $store->consume(databaseRateLimitConsumption('customer-72', '2026-08-01 12:01:00'));

    expect($next->allowed)->toBeTrue()
        ->and($next->remaining)->toBe(1)
        ->and(app(DatabaseManager::class)->connection()->table(verdictTable('rate_limits'))->count())->toBe(2)
        ->and($store->pruneExpired(new DateTimeImmutable('2026-08-01 12:01:00', new DateTimeZone('UTC'))))->toBe(1)
        ->and(app(DatabaseManager::class)->connection()->table(verdictTable('rate_limits'))->count())->toBe(1);
});

it('rejects rate-limit consumption inside an outer transaction on the store connection', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $store = new DatabaseRateLimitStore($connection);

    $connection->beginTransaction();

    try {
        expect(fn () => $store->consume(databaseRateLimitConsumption('customer-72', '2026-08-01 12:00:15')))
            ->toThrow(UnsafeOuterTransaction::class, 'consume a semantic rate-limit unit');
    } finally {
        $connection->rollBack();
    }

    expect($connection->transactionLevel())->toBe(0)
        ->and($connection->table(verdictTable('rate_limits'))->count())->toBe(0);
});
