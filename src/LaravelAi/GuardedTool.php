<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Decisions\ExecutionResult;
use Laravel\Ai\Tools\Request;
use Stringable;

final class GuardedTool extends AbstractVerdictTool
{
    protected function toolKind(): string
    {
        return 'guarded';
    }

    protected function executeAction(ActionEnvelope $envelope, Request $request): ExecutionResult
    {
        return $this->verdict()->run(
            $envelope,
            fn (): Stringable|string => $this->definition()->handle($request),
        );
    }
}
