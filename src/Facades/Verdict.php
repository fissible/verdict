<?php

declare(strict_types=1);

namespace Fissible\Verdict\Facades;

use Fissible\Verdict\VerdictManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Fissible\Verdict\VerdictManager capability(\Fissible\Verdict\Capabilities\Capability $capability)
 * @method static \Fissible\Verdict\VerdictManager releasePolicy(\Fissible\Verdict\Context\ReleasePolicy $policy)
 * @method static \Fissible\Verdict\Context\PendingContextRelease release(array<string, mixed>|\Illuminate\Contracts\Support\Arrayable<string, mixed> $payload)
 * @method static \Fissible\Verdict\Decisions\Evaluation evaluate(\Fissible\Verdict\Actions\ActionEnvelope $envelope)
 * @method static \Fissible\Verdict\Decisions\ExecutionResult run(\Fissible\Verdict\Actions\ActionEnvelope $envelope, callable $executor)
 * @method static \Fissible\Verdict\Decisions\ExecutionResult runBound(\Fissible\Verdict\Actions\ActionEnvelope $envelope)
 * @method static \Fissible\Verdict\LaravelAi\GuardedTool guard(\Laravel\Ai\Contracts\Tool $tool, string $capability, \Fissible\Verdict\Actions\ActionContext|callable $context)
 * @method static \Fissible\Verdict\LaravelAi\BoundTool bound(\Laravel\Ai\Contracts\Tool $definition, string $capability, \Fissible\Verdict\Actions\ActionContext|callable $context)
 * @method static \Fissible\Verdict\Approvals\ApprovalManager approvals()
 *
 * @see VerdictManager
 */
final class Verdict extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VerdictManager::class;
    }
}
