<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Laravel\Ai\Contracts\Agent;

trait RunsVerdictMiddleware
{
    /** @return array<int, mixed> */
    protected function gatherMiddlewareFor(Agent $agent): array
    {
        // Resolve scoped contexts per run: providers are cached across worker requests.
        // Parent retains SDK recording and conversation persistence, inside Verdict's frames.
        return [
            app(VerdictApprovalMiddleware::class),
            ...($agent instanceof HasVerdictRunMiddleware ? $agent->verdictRunMiddleware() : []),
            ...parent::gatherMiddlewareFor($agent),
        ];
    }
}
