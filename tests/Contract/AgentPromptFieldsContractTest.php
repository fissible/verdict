<?php

declare(strict_types=1);

/**
 * @contract-behaviour agent-prompt-fields
 *
 * @contract-fidelity constructed
 *
 * @contract-consequence Verdict's middleware and listeners read the prompt agent, invocation id, and approval-decision fields directly.
 */
use Laravel\Ai\Prompts\AgentPrompt;

it('keeps the adapter-required fields on AgentPrompt', function (): void {
    $fields = array_map(static fn (ReflectionProperty $property): string => $property->getName(), (new ReflectionClass(AgentPrompt::class))->getProperties());

    expect($fields)->toContain('agent')
        ->and(in_array('invocationId', $fields, true))->toBeTrue()
        ->and(method_exists(AgentPrompt::class, 'hasApprovalDecisions'))->toBeTrue();
});
