<?php

declare(strict_types=1);

/**
 * @contract-behaviour resume-mints-distinct-invocation-ids
 *
 * @contract-fidelity constructed
 *
 * @contract-consequence A resumed approval must not merge the original and resumed turns' evidence into one invocation.
 */
use Laravel\Ai\Prompts\AgentPrompt;

it('keeps the invocation id as a per-prompt field rather than approval state', function (): void {
    $invocation = new ReflectionProperty(AgentPrompt::class, 'invocationId');
    $decisions = new ReflectionProperty(AgentPrompt::class, 'approvalDecisions');

    expect($invocation->getType()?->getName())->toBe('string')
        ->and($decisions->getType()?->getName())->toBe('Laravel\\Ai\\Approvals\\Decisions');
});
