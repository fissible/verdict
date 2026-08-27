<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Closure;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Generator;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StreamableAgentResponse;
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

        $approvedToolCalls = LaravelApprovalDecisions::approvedToolCalls($decisions);
        $this->context->push($approvedToolCalls);

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

        StreamableAgentResponseGenerator::wrap($response, function (Closure $originalGenerator) use ($approvedToolCalls): Closure {
            return function () use ($originalGenerator, $approvedToolCalls): Generator {
                $this->context->push($approvedToolCalls);

                try {
                    yield from call_user_func($originalGenerator);
                } finally {
                    $this->context->pop();
                }
            };
        });

        return $response;
    }
}
