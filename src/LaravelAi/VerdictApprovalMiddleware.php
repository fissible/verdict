<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Closure;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Laravel\Ai\Prompts\AgentPrompt;

final readonly class VerdictApprovalMiddleware
{
    public function __construct(private ApprovalExecutionContext $context) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        if ($prompt->approvalDecisions === null) {
            return $next($prompt);
        }

        return $this->context->within(
            $prompt->approvalDecisions,
            fn (): mixed => $next($prompt),
        );
    }
}
