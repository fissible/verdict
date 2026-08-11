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
use Generator;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StreamableAgentResponse;
use LogicException;
use ReflectionProperty;
use Throwable;

final readonly class VerdictProvenanceMiddleware
{
    public function __construct(
        private ProvenanceLedger $provenance,
        private Trust $trust,
        private DataClass $dataClass,
        private ?Source $source = null,
        private ?InvocationContext $invocations = null,
    ) {}

    /**
     * The invocation context this middleware publishes into.
     *
     * Resolved from the container when not injected, so it is the same scoped instance
     * `VerdictManager` reads when recording decision evidence. A fresh instance would correlate
     * nothing while appearing to work.
     */
    private function invocations(): InvocationContext
    {
        return $this->invocations ?? Container::getInstance()->make(InvocationContext::class);
    }

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $source = $this->source ?? Source::user('agent-prompt');

        if ($prompt->invocationId !== null) {
            // The context is established even on the approval-resume path. That path does not
            // re-record prompt provenance — the prompt was recorded when it was first proposed —
            // but decisions reached while resuming belong to the same invocation and must correlate.
            return $this->invocations()->within(
                $prompt->invocationId,
                function () use ($prompt, $next, $source): mixed {
                    if (! $prompt->hasApprovalDecisions()) {
                        $this->provenance->record(
                            correlationId: $prompt->invocationId,
                            source: $source,
                            trust: $this->trust,
                            dataClass: $this->dataClass,
                            channel: ContextChannel::UserInput,
                            content: $prompt->prompt,
                        );
                    }

                    $response = $next($prompt);

                    if ($response instanceof StreamableAgentResponse) {
                        $this->wrapWithDeferredInvocation($response, $prompt->invocationId);
                    }

                    return $response;
                },
            );
        }

        if ($prompt->hasApprovalDecisions()) {
            return $next($prompt);
        }

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
            $response = $next($prompt);
        } catch (Throwable $exception) {
            $registry->forgetIfPending($prompt->agent, $registration);

            throw $exception;
        }

        if (! $response instanceof StreamableAgentResponse) {
            $registry->forgetIfPending($prompt->agent, $registration);

            return $response;
        }

        $this->deferPendingProvenanceCleanup($response, $prompt, $registry, $registration);

        return $response;
    }

    /**
     * Keep the pending registration alive until Laravel AI dispatches StreamingAgent inside a
     * lazy response generator. Mutating the response in place preserves framework-owned state
     * and callbacks, following the same guarded approach as VerdictApprovalMiddleware.
     */
    private function deferPendingProvenanceCleanup(
        StreamableAgentResponse $response,
        AgentPrompt $prompt,
        PromptProvenanceRegistry $registry,
        object $registration,
    ): void {
        $property = new ReflectionProperty(StreamableAgentResponse::class, 'generator');
        $originalGenerator = $property->getValue($response);

        if (! $originalGenerator instanceof Closure) {
            throw new LogicException('Expected Laravel AI\'s StreamableAgentResponse to hold a Closure generator.');
        }

        $property->setValue($response, function () use ($originalGenerator, $prompt, $registry, $registration): Generator {
            try {
                yield from call_user_func($originalGenerator);
            } finally {
                $registry->forgetIfPending($prompt->agent, $registration);
            }
        });
    }

    /**
     * Keep the invocation frame alive for lazy stream iteration without replacing the response.
     *
     * StreamableAgentResponse does not expose its generator, so this follows the same guarded
     * Reflection-based in-place wrapping used by VerdictApprovalMiddleware. Rebuilding the response
     * would risk dropping framework-owned state and callbacks registered by another middleware.
     */
    private function wrapWithDeferredInvocation(StreamableAgentResponse $response, string $invocationId): void
    {
        $property = new ReflectionProperty(StreamableAgentResponse::class, 'generator');
        $originalGenerator = $property->getValue($response);

        if (! $originalGenerator instanceof Closure) {
            throw new LogicException('Expected Laravel AI\'s StreamableAgentResponse to hold a Closure generator.');
        }

        $property->setValue($response, function () use ($originalGenerator, $invocationId): Generator {
            $invocations = $this->invocations();
            $invocations->push($invocationId);

            try {
                yield from call_user_func($originalGenerator);
            } finally {
                $invocations->pop();
            }
        });
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
