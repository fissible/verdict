<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ActionIntentStore;
use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Fissible\Verdict\Intents\ActionIntent;
use Fissible\Verdict\Intents\DatabaseActionIntentStore;
use Fissible\Verdict\Intents\InMemoryActionIntentStore;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('intents'));
    $schema->create(verdictTable('intents'), function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('capability');
        $table->char('configuration_fingerprint', 64);
        $table->char('actor_fingerprint', 64)->nullable();
        $table->char('subject_fingerprint', 64)->nullable();
        $table->char('execution_target_identity_fingerprint', 64)->nullable();
        $table->char('argument_fingerprint', 64);
        $table->string('invocation_id')->nullable();
        $table->timestamp('recorded_at');
    });
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('intents'));
});

function actionIntent(string $id = 'intent-1'): ActionIntent
{
    return new ActionIntent(
        id: $id,
        capability: 'orders.refund',
        configurationFingerprint: hash('sha256', 'configuration'),
        actorFingerprint: hash('sha256', 'actor'),
        subjectFingerprint: hash('sha256', 'subject'),
        executionTargetIdentityFingerprint: hash('sha256', 'target'),
        argumentFingerprint: hash('sha256', 'arguments'),
        invocationId: 'invocation-1',
        recordedAt: new DateTimeImmutable('2026-08-25 12:00:00', new DateTimeZone('UTC')),
    );
}

dataset('intent stores', [
    'database' => [fn (): ActionIntentStore => new DatabaseActionIntentStore(
        app(DatabaseManager::class)->connection(),
        verdictTable('intents'),
    )],
    'in-memory' => [fn (): ActionIntentStore => new InMemoryActionIntentStore],
]);

it('records an intent and finds it by id', function (callable $factory): void {
    $store = $factory();
    $intent = actionIntent();

    $store->record($intent);
    $found = $store->find('intent-1');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe('intent-1')
        ->and($found->capability)->toBe('orders.refund')
        ->and($found->configurationFingerprint)->toBe(hash('sha256', 'configuration'))
        ->and($found->actorFingerprint)->toBe(hash('sha256', 'actor'))
        ->and($found->subjectFingerprint)->toBe(hash('sha256', 'subject'))
        ->and($found->executionTargetIdentityFingerprint)->toBe(hash('sha256', 'target'))
        ->and($found->argumentFingerprint)->toBe(hash('sha256', 'arguments'))
        ->and($found->invocationId)->toBe('invocation-1')
        ->and($found->recordedAt->format('Y-m-d H:i:s'))->toBe('2026-08-25 12:00:00');
})->with('intent stores');

it('returns null for an unknown intent id', function (callable $factory): void {
    expect($factory()->find('missing'))->toBeNull();
})->with('intent stores');

it('preserves null identity fields through a round trip', function (callable $factory): void {
    $store = $factory();
    $store->record(new ActionIntent(
        id: 'intent-nulls',
        capability: 'orders.lookup',
        configurationFingerprint: hash('sha256', 'configuration'),
        actorFingerprint: null,
        subjectFingerprint: null,
        executionTargetIdentityFingerprint: null,
        argumentFingerprint: hash('sha256', 'arguments'),
        invocationId: null,
        recordedAt: new DateTimeImmutable('2026-08-25 12:00:00', new DateTimeZone('UTC')),
    ));

    $found = $store->find('intent-nulls');

    expect($found->actorFingerprint)->toBeNull()
        ->and($found->subjectFingerprint)->toBeNull()
        ->and($found->executionTargetIdentityFingerprint)->toBeNull()
        ->and($found->invocationId)->toBeNull();
})->with('intent stores');

it('refuses to record the same intent id twice', function (callable $factory): void {
    $store = $factory();
    $store->record(actionIntent());

    expect(fn () => $store->record(actionIntent()))->toThrow(Exception::class);
})->with('intent stores');

it('refuses a database intent write inside an unsafe outer transaction', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $store = new DatabaseActionIntentStore($connection, verdictTable('intents'));

    $connection->beginTransaction();

    try {
        expect(fn () => $store->record(actionIntent()))->toThrow(UnsafeOuterTransaction::class);
    } finally {
        $connection->rollBack();
    }

    expect($store->find('intent-1'))->toBeNull();
});

it('reports its table and whether it exists', function (): void {
    $store = new DatabaseActionIntentStore(app(DatabaseManager::class)->connection(), verdictTable('intents'));

    expect($store->table())->toBe(verdictTable('intents'))
        ->and($store->hasTable())->toBeTrue();
});
