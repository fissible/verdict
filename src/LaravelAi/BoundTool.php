<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Decisions\ExecutionResult;
use Laravel\Ai\Tools\Request;

final class BoundTool extends AbstractVerdictTool
{
    protected function toolKind(): string
    {
        return 'bound';
    }

    protected function supportsVerifiedConfirmation(): bool
    {
        return true;
    }

    protected function executeAction(ActionEnvelope $envelope, Request $request): ExecutionResult
    {
        return $this->verdict()->runBound($envelope);
    }
}
