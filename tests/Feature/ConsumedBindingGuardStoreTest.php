<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\DatabaseConsumedBindingGuardStore;
use Fissible\Verdict\Approvals\InMemoryConsumedBindingGuardStore;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Illuminate\Database\DatabaseManager;

// Slice 1 of ADR 0039: the permanent replay guard's persistence primitive. The store records a
// consumed binding's raw-32-byte digest with its consumption instant, and answers whether one is
// present. consume() wiring + admission lock + backfill are slice 2; issue() guard-fallback is
// slice 3. Both shipped implementations must answer identically — the in-memory store is what
// adopters bind in their own suites, so a drift is a silent security-test lie.

function guardTable(): string
{
    return 'verdict_consumed_binding_guards';
}

// Distinct raw 32-byte digests, including one carrying NUL and high-bit bytes to prove the store
// keeps a binary key faithfully rather than treating it as text.
function dgA(): string
{
    return hex2bin(str_repeat('a', 64));
}
function dgB(): string
{
    return hex2bin(str_repeat('b', 64));
}
function dgC(): string
{
    return hex2bin(str_repeat('c', 64));
}
function dgBinary(): string
{
    return "\x00".str_repeat("\x2a", 30)."\xff";
} // 32 bytes, NUL + 0xff

function databaseGuardStore(): DatabaseConsumedBindingGuardStore
{
    return new DatabaseConsumedBindingGuardStore(app(DatabaseManager::class)->connection(), guardTable());
}

beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/create_verdict_consumed_binding_guards_table.php.stub')->up();
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(guardTable());
});

/** @return array<string, array{0: callable(): ConsumedBindingGuardStore}> */
dataset('guard stores', [
    'in-memory' => [fn (): ConsumedBindingGuardStore => new InMemoryConsumedBindingGuardStore],
    'database' => [fn (): ConsumedBindingGuardStore => databaseGuardStore()],
]);

// ── shared contract, both implementations ─────────────────────────────────────────────────────

it('reports absence for a digest it has never recorded', function (callable $make): void {
    expect($make()->has(dgA()))->toBeFalse();
})->with('guard stores');

it('reports presence for a remembered digest and absence for others', function (callable $make): void {
    $store = $make();
    $store->remember(dgA(), new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')));

    expect($store->has(dgA()))->toBeTrue()
        ->and($store->has(dgB()))->toBeFalse();
})->with('guard stores');

it('keeps every remembered digest, not only the most recent', function (callable $make): void {
    $store = $make();
    $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    $store->remember(dgA(), $at);
    $store->remember(dgB(), $at);

    expect($store->has(dgA()))->toBeTrue()
        ->and($store->has(dgB()))->toBeTrue()
        ->and($store->has(dgC()))->toBeFalse();

    $store->remember(dgA(), $at); // re-remembering A must not evict B
    expect($store->has(dgB()))->toBeTrue();
})->with('guard stores');

it('is idempotent: remembering the same digest twice neither throws nor loses it', function (callable $make): void {
    $store = $make();
    $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    $store->remember(dgA(), $at);
    $store->remember(dgA(), $at);

    expect($store->has(dgA()))->toBeTrue();
})->with('guard stores');

it('keeps a binary digest (NUL and high bytes) faithfully', function (callable $make): void {
    $store = $make();
    $store->remember(dgBinary(), new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')));

    expect($store->has(dgBinary()))->toBeTrue()
        ->and($store->has(dgA()))->toBeFalse();
})->with('guard stores');

// ── database persistence specifics ────────────────────────────────────────────────────────────

it('stores the digest bytes faithfully, without stripping NUL or re-encoding', function (): void {
    databaseGuardStore()->remember(dgBinary(), new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')));

    // Read the raw column, not has(): symmetric mangling in remember()/has() would hide it.
    $stored = app(DatabaseManager::class)->connection()->table(guardTable())->value('digest');

    expect($stored)->toBe(dgBinary())
        ->and(strlen((string) $stored))->toBe(32);
});

it('persists durably: a fresh store instance sees a digest an earlier one recorded', function (): void {
    databaseGuardStore()->remember(dgA(), new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')));

    // A brand-new instance, so an implementation backed by an instance-local array cannot pass.
    expect(databaseGuardStore()->has(dgA()))->toBeTrue();
});

it('keeps exactly one row per digest on a duplicate remember', function (): void {
    $store = databaseGuardStore();
    $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    $store->remember(dgA(), $at);
    $store->remember(dgA(), $at);

    expect(app(DatabaseManager::class)->connection()->table(guardTable())->count())->toBe(1);
});

it('stores the supplied consumption instant', function (): void {
    databaseGuardStore()->remember(dgA(), new DateTimeImmutable('2026-03-04 05:06:07', new DateTimeZone('UTC')));

    $consumedAt = app(DatabaseManager::class)->connection()->table(guardTable())
        ->value('consumed_at');

    expect((new DateTimeImmutable((string) $consumedAt, new DateTimeZone('UTC')))->format('Y-m-d H:i:s'))
        ->toBe('2026-03-04 05:06:07');
});

it('preserves the first recorded instant, not a later duplicate, so consumption history is not rewritten', function (): void {
    $store = databaseGuardStore();
    $store->remember(dgA(), new DateTimeImmutable('2026-03-04 05:06:07', new DateTimeZone('UTC')));
    $store->remember(dgA(), new DateTimeImmutable('2026-09-09 09:09:09', new DateTimeZone('UTC')));

    $consumedAt = app(DatabaseManager::class)->connection()->table(guardTable())
        ->value('consumed_at');

    expect((new DateTimeImmutable((string) $consumedAt, new DateTimeZone('UTC')))->format('Y-m-d H:i:s'))
        ->toBe('2026-03-04 05:06:07');
});
