<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

interface HasVerdictRunMiddleware
{
    /**
     * AgentPrompt middleware for the whole run, including approval resumption.
     * Laravel AI's HasMiddleware is step-scoped and cannot host these gates.
     *
     * @return list<object|class-string>
     */
    public function verdictRunMiddleware(): array;
}
