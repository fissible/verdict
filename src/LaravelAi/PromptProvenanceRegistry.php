<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Laravel\Ai\Contracts\Agent;

final class PromptProvenanceRegistry
{
    /** @var array<int, list<array{prompt: string, source: Source, trust: Trust, dataClass: DataClass}>> */
    private array $pending = [];

    public function remember(
        Agent $agent,
        string $prompt,
        Source $source,
        Trust $trust,
        DataClass $dataClass,
    ): void {
        $this->pending[spl_object_id($agent)][] = [
            'prompt' => $prompt,
            'source' => $source,
            'trust' => $trust,
            'dataClass' => $dataClass,
        ];
    }

    /** @return array{prompt: string, source: Source, trust: Trust, dataClass: DataClass}|null */
    public function consume(Agent $agent): ?array
    {
        $key = spl_object_id($agent);

        if (($this->pending[$key] ?? []) === []) {
            return null;
        }

        return array_shift($this->pending[$key]);
    }
}
