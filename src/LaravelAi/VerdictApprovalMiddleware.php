<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Closure;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Generator;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StreamableAgentResponse;
use LogicException;
use ReflectionProperty;
use Throwable;

final readonly class VerdictApprovalMiddleware
{
    public function __construct(private ApprovalExecutionContext $context) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $decisions = $prompt->approvalDecisions;

        if ($decisions === null) {
            return $next($prompt);
        }

        $this->context->push($decisions);

        try {
            $response = $next($prompt);
        } catch (Throwable $e) {
            $this->context->pop();

            throw $e;
        }

        if (! $response instanceof StreamableAgentResponse) {
            $this->context->pop();

            return $response;
        }

        // A streamed response is lazy: $next($prompt) has already returned, but the
        // underlying generator — and any tool call inside it — has not run yet. The push
        // above was necessary in case $next() executed a tool synchronously (the
        // non-streamed path above), but it must not survive past this point for a streamed
        // response: if the caller never iterates it, that eager push would otherwise sit on
        // the stack for the rest of this request's scope, visible to unrelated later
        // checks. Undo it here, then re-push only when — and if — real iteration begins.
        $this->context->pop();

        $this->wrapWithDeferredApproval($response, $decisions);

        return $response;
    }

    /**
     * Mutates $response's generator in place so the approval frame is pushed only when the
     * stream is actually iterated, and popped when that iteration ends — on normal
     * completion, on an exception partway through, or (via PHP's generator cleanup) if
     * iteration is abandoned before finishing. Mutating in place, rather than constructing
     * a replacement StreamableAgentResponse, preserves every other piece of state the
     * object may already carry (conversation binding, then() callbacks registered by an
     * inner middleware, Vercel protocol configuration, and so on) — none of which this
     * class has any way to faithfully reconstruct.
     *
     * StreamableAgentResponse exposes no accessor or mutator for its generator, so this
     * reads and rewrites the protected property via Reflection, type-checking the value
     * read back before use. If a future Laravel AI release renames or restructures that
     * property, this throws — a ReflectionException, or the LogicException below —
     * rather than silently misbehaving.
     */
    private function wrapWithDeferredApproval(StreamableAgentResponse $response, Decisions $decisions): void
    {
        $property = new ReflectionProperty(StreamableAgentResponse::class, 'generator');
        $originalGenerator = $property->getValue($response);

        if (! $originalGenerator instanceof Closure) {
            throw new LogicException('Expected Laravel AI\'s StreamableAgentResponse to hold a Closure generator.');
        }

        $property->setValue($response, function () use ($originalGenerator, $decisions): Generator {
            $this->context->push($decisions);

            try {
                yield from call_user_func($originalGenerator);
            } finally {
                $this->context->pop();
            }
        });
    }
}
