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

    /** The ReproductionMetadata 'sampling' component a control run requires. */
    public function component(): string
    {
        $component = sprintf('%s temperature=%s', $this->mode->value, $this->format($this->temperature));

        return $this->seed === null ? $component : "{$component} seed={$this->seed}";
    }

    /**
     * What is actually sent to Ollama, via `HasProviderOptions` — the gateway merges these into
     * the request body's `options`.
     *
     * @return array<string, float|int>
     */
    public function providerOptions(): array
    {
        $options = ['temperature' => $this->temperature];

        if ($this->seed !== null) {
            $options['seed'] = $this->seed;
        }

        return $options;
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
    }
}
