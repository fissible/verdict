<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Closure;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Laravel\Ai\Prompts\AgentPrompt;

final readonly class VerdictProvenanceMiddleware
{
    public function __construct(
        private ProvenanceLedger $provenance,
        private Trust $trust,
        private DataClass $dataClass,
        private ?Source $source = null,
    ) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        if ($prompt->hasApprovalDecisions()) {
            return $next($prompt);
        }

        $source = $this->source ?? Source::user('agent-prompt');

        if ($prompt->invocationId !== null) {
            $this->provenance->record(
                correlationId: $prompt->invocationId,
                source: $source,
                trust: $this->trust,
                dataClass: $this->dataClass,
                channel: ContextChannel::UserInput,
                content: $prompt->prompt,
            );
        } else {
            $container = Container::getInstance();

            if (! $container->bound(PromptProvenanceRegistry::class)) {
                throw new InvalidArgumentException('A Laravel AI invocation ID is required when prompt provenance is not container-managed.');
            }

            $registry = $container->make(PromptProvenanceRegistry::class);
            $registration = $registry->remember(
                agent: $prompt->agent,
                prompt: $prompt->prompt,
                source: $source,
                trust: $this->trust,
                dataClass: $this->dataClass,
            );

            try {
                return $next($prompt);
            } finally {
                $registry->forgetIfPending($prompt->agent, $registration);
            }
        }

        return $next($prompt);
    }

    public function recordRetrievedDocument(
        string $correlationId,
        mixed $document,
        Source $source,
        Trust $trust,
        DataClass $dataClass,
        ?string $componentLabel = null,
        ?string $componentVersion = null,
    ): ProvenanceEntry {
        return $this->provenance->record(
            correlationId: $correlationId,
            source: $source,
            trust: $trust,
            dataClass: $dataClass,
            channel: ContextChannel::RetrievedDocument,
            content: $document,
            componentLabel: $componentLabel,
            componentVersion: $componentVersion,
        );
    }
}
