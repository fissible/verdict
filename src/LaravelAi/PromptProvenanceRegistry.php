<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Laravel\Ai\Contracts\Agent;
use LogicException;
use WeakMap;

final class PromptProvenanceRegistry
{
    /** @var WeakMap<Agent, array{registration: object, prompt: string, source: Source, trust: Trust, dataClass: DataClass}> */
    private WeakMap $pending;

    public function __construct()
    {
        $this->pending = new WeakMap;
    }

    public function remember(
        Agent $agent,
        string $prompt,
        Source $source,
        Trust $trust,
        DataClass $dataClass,
    ): object {
        if (isset($this->pending[$agent])) {
            throw new LogicException('Prompt provenance is already pending for this agent instance.');
        }

        $registration = new \stdClass;

        $this->pending[$agent] = [
            'registration' => $registration,
            'prompt' => $prompt,
            'source' => $source,
            'trust' => $trust,
            'dataClass' => $dataClass,
        ];

        return $registration;
    }

    /** @return array{prompt: string, source: Source, trust: Trust, dataClass: DataClass}|null */
    public function consume(Agent $agent): ?array
    {
        if (! isset($this->pending[$agent])) {
            return null;
        }

        $pending = $this->pending[$agent];

        unset($this->pending[$agent]);

        return [
            'prompt' => $pending['prompt'],
            'source' => $pending['source'],
            'trust' => $pending['trust'],
            'dataClass' => $pending['dataClass'],
        ];
    }

    public function forgetIfPending(Agent $agent, object $registration): void
    {
        if (! isset($this->pending[$agent]) || $this->pending[$agent]['registration'] !== $registration) {
            return;
        }

        unset($this->pending[$agent]);
    }
}
