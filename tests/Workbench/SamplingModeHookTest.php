<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\ControlSamplingMode;
use Workbench\App\Storefront\StorefrontLiveSampling;

/**
 * The workbench's VERDICT_SAMPLING hook selects the control-arm decoding mode for a recorded run:
 * greedy (default, reproducible regression pair) or sampled (independent-sample rate). Infra for a
 * deliberate run, mirroring STOREFRONT_LIVE_MODEL / VERDICT_MAX_TRIALS.
 */
afterEach(function (): void {
    putenv('VERDICT_SAMPLING');
    putenv('VERDICT_SAMPLING_TEMPERATURE');
    $this->app->forgetInstance(StorefrontLiveSampling::class);
});

it('defaults to greedy decoding when VERDICT_SAMPLING is unset', function (): void {
    putenv('VERDICT_SAMPLING');
    $this->app->forgetInstance(StorefrontLiveSampling::class);

    expect(app(StorefrontLiveSampling::class)->mode)->toBe(ControlSamplingMode::Greedy);
});

it('selects sampled decoding with an optional temperature from the env', function (): void {
    putenv('VERDICT_SAMPLING=sampled');
    putenv('VERDICT_SAMPLING_TEMPERATURE=0.9');
    $this->app->forgetInstance(StorefrontLiveSampling::class);

    $sampling = app(StorefrontLiveSampling::class);

    expect($sampling->mode)->toBe(ControlSamplingMode::Sampled)
        ->and($sampling->component())->toBe('sampled temperature=0.9');
});
