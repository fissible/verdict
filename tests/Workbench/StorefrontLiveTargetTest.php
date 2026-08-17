<?php

declare(strict_types=1);

use Workbench\App\Storefront\StorefrontLiveSampling;
use Workbench\App\Storefront\StorefrontLiveSuiteFactory;
use Workbench\App\Storefront\StorefrontLiveTarget;

/**
 * The live run's target (provider + model) decides which decoding parameters its API will accept.
 * Anthropic's Claude 5 generation removed `temperature`; only Ollama accepts a decoding `seed`.
 * The workbench must not send a parameter the target rejects (it 400s the whole run), and — the
 * point that matters for evidence — must not *attest* a value it did not send. Both facts derive
 * from one place so the label and the request cannot drift. See ADR 0023.
 */
afterEach(function (): void {
    putenv('STOREFRONT_LIVE_PROVIDER');
    putenv('STOREFRONT_LIVE_MODEL');
    putenv('VERDICT_SAMPLING');
    $this->app->forgetInstance(StorefrontLiveSampling::class);
});

it('reports that Claude 5 rejects temperature and never accepts seed', function (string $model): void {
    $target = new StorefrontLiveTarget('anthropic', $model);

    expect($target->acceptsTemperature())->toBeFalse()
        ->and($target->acceptsSeed())->toBeFalse();
})->with([
    'opus-5' => 'claude-opus-5',
    'sonnet-5' => 'claude-sonnet-5',
    'fable-5' => 'claude-fable-5',
    'dated sonnet-5' => 'claude-sonnet-5-20260101',
    // A model family the enumerated predicate did not name, and a 5-with-suffix shape that is
    // neither a dash nor end-of-string — both are gen 5, both reject temperature.
    'haiku-5' => 'claude-haiku-5',
    'opus-5 1m-context' => 'claude-opus-5[1m]',
]);

it('reports that pre-5 Anthropic models accept temperature but still reject seed', function (string $model): void {
    $target = new StorefrontLiveTarget('anthropic', $model);

    expect($target->acceptsTemperature())->toBeTrue()
        ->and($target->acceptsSeed())->toBeFalse();
})->with([
    'haiku-4.5' => 'claude-haiku-4-5-20251001',
    'sonnet-4.6' => 'claude-sonnet-4-6',
]);

it('reports that Ollama accepts both temperature and seed', function (): void {
    $target = new StorefrontLiveTarget('ollama', 'gpt-oss:20b');

    expect($target->acceptsTemperature())->toBeTrue()
        ->and($target->acceptsSeed())->toBeTrue();
});

it('builds the target from the env with the same defaults the agent uses', function (): void {
    putenv('STOREFRONT_LIVE_PROVIDER');
    putenv('STOREFRONT_LIVE_MODEL');
    $default = StorefrontLiveTarget::fromEnv();

    expect($default->provider)->toBe('ollama')
        ->and($default->model)->toBe('gpt-oss:20b');

    putenv('STOREFRONT_LIVE_PROVIDER=Anthropic');
    putenv('STOREFRONT_LIVE_MODEL=claude-sonnet-5');
    $sonnet = StorefrontLiveTarget::fromEnv();

    expect($sonnet->provider)->toBe('anthropic')
        ->and($sonnet->model)->toBe('claude-sonnet-5');
});

it('omits the temperature and attests provider-default when the target rejects it', function (): void {
    $sonnet = new StorefrontLiveTarget('anthropic', 'claude-sonnet-5');
    $sampled = StorefrontLiveSampling::sampled(0.8);

    expect($sampled->providerOptions($sonnet))->toBe([])
        ->and($sampled->component($sonnet))->toBe('sampled temperature=provider-default');
});

it('keeps the temperature but drops the seed when the target takes one but not the other', function (): void {
    $haiku = new StorefrontLiveTarget('anthropic', 'claude-haiku-4-5-20251001');
    $greedy = StorefrontLiveSampling::greedy();

    // Haiku accepts temperature=0 (still argmax-deterministic) but rejects Ollama's seed.
    expect($greedy->providerOptions($haiku))->toBe(['temperature' => 0.0])
        ->and($greedy->component($haiku))->toBe('greedy temperature=0');
});

it('sends and attests both parameters unchanged for an Ollama target', function (): void {
    $ollama = new StorefrontLiveTarget('ollama', 'gpt-oss:20b');
    $greedy = StorefrontLiveSampling::greedy();

    expect($greedy->providerOptions($ollama))->toBe(['temperature' => 0.0, 'seed' => 7])
        ->and($greedy->component($ollama))->toBe('greedy temperature=0 seed=7');
});

it('refuses to build a greedy run against a model whose API cannot pin decoding', function (): void {
    putenv('STOREFRONT_LIVE_PROVIDER=anthropic');
    putenv('STOREFRONT_LIVE_MODEL=claude-sonnet-5');
    putenv('VERDICT_SAMPLING=greedy');
    $this->app->forgetInstance(StorefrontLiveSampling::class);

    expect(fn () => app(StorefrontLiveSuiteFactory::class)->makeForTrial(0))
        ->toThrow(LogicException::class, 'greedy');
});
