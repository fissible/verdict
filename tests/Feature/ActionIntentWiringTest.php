<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\ActionIntentStore;
use Fissible\Verdict\Intents\ActionIntent;
use Fissible\Verdict\Intents\ActionIntentManager;
use Fissible\Verdict\Intents\DatabaseActionIntentStore;
use Fissible\Verdict\Intents\InMemoryActionIntentStore;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Support\ServiceProvider;

final class WiringTestActionIntentStore implements ActionIntentStore
{
    public function record(ActionIntent $intent): void {}

    public function find(string $id): ?ActionIntent
    {
        return null;
    }
}

it('resolves the database intent store from the shipped default', function (): void {
    // The base TestCase configures the in-memory store; this test asserts the shipped default.
    config()->set('verdict.intents.store', DatabaseActionIntentStore::class);
    $this->app->forgetInstance(ActionIntentStore::class);

    $store = app(ActionIntentStore::class);

    expect($store)->toBeInstanceOf(DatabaseActionIntentStore::class)
        ->and($store->table())->toBe('verdict_action_intents');
});

it('resolves the configured intent table name', function (): void {
    config()->set('verdict.intents.store', DatabaseActionIntentStore::class);
    config()->set('verdict.intents.table', 'renamed_action_intents');
    $this->app->forgetInstance(ActionIntentStore::class);

    expect(app(ActionIntentStore::class)->table())->toBe('renamed_action_intents');
});

it('resolves a custom intent store through the container', function (): void {
    config()->set('verdict.intents.store', WiringTestActionIntentStore::class);
    $this->app->forgetInstance(ActionIntentStore::class);

    expect(app(ActionIntentStore::class))->toBeInstanceOf(WiringTestActionIntentStore::class);
});

it('refuses a configured intent store that does not implement the contract', function (): void {
    config()->set('verdict.intents.store', InMemoryActionIntentStore::class);
    $this->app->forgetInstance(ActionIntentStore::class);

    expect(app(ActionIntentStore::class))->toBeInstanceOf(InMemoryActionIntentStore::class);

    config()->set('verdict.intents.store', stdClass::class);
    $this->app->forgetInstance(ActionIntentStore::class);

    expect(fn () => app(ActionIntentStore::class))->toThrow(LogicException::class);
});

it('wires the intent manager against the global lever', function (): void {
    config()->set('verdict.intents.required', true);
    config()->set('verdict.intents.store', InMemoryActionIntentStore::class);
    $this->app->forgetInstance(ActionIntentStore::class);
    $this->app->forgetInstance(ActionIntentManager::class);

    $manager = app(ActionIntentManager::class);
    $capability = Capability::usingPolicy('orders.refund', 'refund', fn () => null);

    expect($manager->required($capability))->toBeTrue()
        ->and($manager->required($capability->requiresIntentRecord(false)))->toBeFalse();
});

it('publishes the action-intent migration independently', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-intent-migrations',
    );

    expect($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('create_verdict_action_intents_table.php.stub');
});
