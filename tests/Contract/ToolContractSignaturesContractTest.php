<?php

declare(strict_types=1);

/**
 * @contract-behaviour tool-contract-signatures
 *
 * @contract-fidelity constructed
 *
 * @contract-consequence AbstractVerdictTool and the evaluation capturing tools must remain valid Laravel AI Tool and Approvable implementations.
 */
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

it('keeps tool handling and approval hooks callable with Laravel AI requests', function (): void {
    $toolHandle = new ReflectionMethod(Tool::class, 'handle');
    $approvalHook = new ReflectionMethod(Approvable::class, 'shouldRequestApproval');

    expect($toolHandle->getParameters()[0]->getType()?->getName())->toBe(Request::class)
        ->and($approvalHook->getParameters()[0]->getType()?->getName())->toBe(Request::class)
        ->and((string) $approvalHook->getReturnType())->toBe('?Laravel\\Ai\\Approvals\\Approval');
});
