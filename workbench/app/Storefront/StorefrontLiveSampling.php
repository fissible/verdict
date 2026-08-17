<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Fissible\Verdict\Evaluation\ControlSamplingMode;

/**
 * The workbench's decoding declaration, in one value: the provider options actually sent to
 * Ollama and the `sampling` reproduction component a control run attests are derived from the
 * same instance, so the label and the request cannot drift apart. ADR 0023's caveat still holds —
 * the declaration is attested, not verified — but a single source of truth narrows the gap to
 * "the provider ignored its options", not "the workbench mislabeled itself".
 */
final readonly class StorefrontLiveSampling
{
    private function __construct(
        public ControlSamplingMode $mode,
        public float $temperature,
        public ?int $seed,
    ) {}

    /** Deterministic decoding: the only mode under which per-trial pairs are matched pairs. */
    public static function greedy(int $seed = 7): self
    {
        return new self(ControlSamplingMode::Greedy, 0.0, $seed);
    }

    /** Independent draws: rates are estimable, per-trial pairing is not claimed. */
    public static function sampled(float $temperature = 0.8): self
    {
        return new self(ControlSamplingMode::Sampled, $temperature, null);
    }

    /**
     * The ReproductionMetadata 'sampling' component a control run requires. Derived from the same
     * `effective()` computation as `providerOptions()`, so the attested label describes exactly
     * what the request carried: where the target rejects `temperature`, it reads
     * `temperature=provider-default` rather than a number that was never sent.
     */
    public function component(StorefrontLiveTarget $target): string
    {
        [$temperature, $seed] = $this->effective($target);

        $temperature = $temperature === null ? 'provider-default' : $this->format($temperature);
        $component = "{$this->mode->value} temperature={$temperature}";

        return $seed === null ? $component : "{$component} seed={$seed}";
    }

    /**
     * What is actually sent to the provider, via `HasProviderOptions` — the gateway merges these
     * into the request body's `options`. A parameter the target's API rejects is omitted (an
     * absent `$target` is the Ollama-shaped default that accepts both).
     *
     * @return array<string, float|int>
     */
    public function providerOptions(StorefrontLiveTarget $target): array
    {
        [$temperature, $seed] = $this->effective($target);

        $options = [];

        if ($temperature !== null) {
            $options['temperature'] = $temperature;
        }

        if ($seed !== null) {
            $options['seed'] = $seed;
        }

        return $options;
    }

    /**
     * The temperature and seed this declaration can actually send to `$target`, each nulled where
     * that target's API would reject it. One source of truth for both the request and its label.
     *
     * @return array{0: ?float, 1: ?int}
     */
    private function effective(StorefrontLiveTarget $target): array
    {
        $temperature = $target->acceptsTemperature() ? $this->temperature : null;
        $seed = ($this->seed !== null && $target->acceptsSeed()) ? $this->seed : null;

        return [$temperature, $seed];
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
    }
}
