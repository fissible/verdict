<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

/**
 * The provider + model a live run targets, and the one fact the decoding declaration needs from
 * it: which decoding parameters that target's API will accept. Two APIs, two different answers —
 *
 *  - Anthropic's Claude 5 generation (`claude-{opus,sonnet,fable}-5`) removed `temperature`
 *    outright: sending it returns HTTP 400 `temperature is deprecated for this model`. Earlier
 *    Anthropic models (Haiku 4.5, Sonnet 4.x) still accept it.
 *  - `seed` is an Ollama decoding option; Anthropic rejects it (`seed: Extra inputs are not
 *    permitted`) on every model, Claude 5 or not.
 *
 * `StorefrontLiveSampling` asks these two questions so it never sends — and never *attests* — a
 * parameter the target would reject. Anything not covered here fails loud at the provider (a 400),
 * which is the intended backstop for models added after this was written, not a silent fallback.
 */
final readonly class StorefrontLiveTarget
{
    public function __construct(
        public string $provider,
        public string $model,
    ) {}

    /**
     * Resolved from the same env the agent reads, with the same defaults, so the target the
     * request is built for and the target the run attests are one value.
     */
    public static function fromEnv(): self
    {
        $provider = getenv('STOREFRONT_LIVE_PROVIDER');
        $provider = is_string($provider) && trim($provider) !== '' ? strtolower(trim($provider)) : 'ollama';

        $model = getenv('STOREFRONT_LIVE_MODEL');
        $model = is_string($model) && trim($model) !== '' ? trim($model) : 'gpt-oss:20b';

        return new self($provider, $model);
    }

    /**
     * False only for Anthropic's Claude 5 generation, which dropped the parameter. Greedy decoding
     * depends on this — without a settable temperature there is no way to pin the arm — so a false
     * here makes greedy unrunnable against this target (see `StorefrontLiveSuiteFactory`).
     */
    public function acceptsTemperature(): bool
    {
        if ($this->provider !== 'anthropic') {
            return true;
        }

        // Any Claude 5 family — `claude-<family>-5` where the 5 is a generation, not the leading
        // digit of a longer number. The negative-lookahead-for-digit (rather than requiring `-`
        // or end) admits every real suffix shape: a bare `-5`, a dated `-5-20260101`, a
        // `-5[1m]` context variant, an unnamed family like `haiku-5`. It still lets 4.x through —
        // `claude-haiku-4-5-…`, `claude-sonnet-4-6` — because their `-5`/`-6` does not follow the
        // family directly. Not leaning on the provider 400 here is the point: that 400 is the
        // harness-blind symptom this predicate exists to prevent, not a safety net to fall back on.
        return preg_match('/^claude-[a-z]+-5(?![0-9])/', $this->model) === 0;
    }

    /** Only Ollama accepts a decoding `seed`; every Anthropic model rejects it. */
    public function acceptsSeed(): bool
    {
        return $this->provider === 'ollama';
    }
}
