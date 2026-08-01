<?php

declare(strict_types=1);

use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitConsumption;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_rate_limit_buckets');
    $schema->create('verdict_rate_limit_buckets', function (Blueprint $table): void {
        $table->char('bucket_fingerprint', 64);
        $table->timestamp('window_starts_at');
        $table->timestamp('reset_at');
        $table->unsignedInteger('attempts');
        $table->timestamps();
        $table->unique(['bucket_fingerprint', 'window_starts_at']);
        $table->index('reset_at');
    });
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists('verdict_rate_limit_buckets');
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
        ->and(app(DatabaseManager::class)->connection()->table('verdict_rate_limit_buckets')->value('attempts'))
        ->toBe(2);
});

it('starts a fresh bucket at the exact window boundary and prunes expired buckets', function (): void {
    $store = databaseRateLimitStore();
    $store->consume(databaseRateLimitConsumption('customer-72', '2026-08-01 12:00:59'));
    $next = $store->consume(databaseRateLimitConsumption('customer-72', '2026-08-01 12:01:00'));

    expect($next->allowed)->toBeTrue()
        ->and($next->remaining)->toBe(1)
        ->and(app(DatabaseManager::class)->connection()->table('verdict_rate_limit_buckets')->count())->toBe(2)
        ->and($store->pruneExpired(new DateTimeImmutable('2026-08-01 12:01:00', new DateTimeZone('UTC'))))->toBe(1)
        ->and(app(DatabaseManager::class)->connection()->table('verdict_rate_limit_buckets')->count())->toBe(1);
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
        ->and($connection->table('verdict_rate_limit_buckets')->count())->toBe(0);
});
