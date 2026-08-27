<?php

declare(strict_types=1);

/**
 * @contract-behaviour invocation-id-on-tool-invoked
 *
 * @contract-fidelity constructed
 *
 * @contract-consequence Verdict correlates tool-result provenance to the Laravel AI invocation that produced it.
 */
use Laravel\Ai\Events\ToolInvoked;

it('exposes invocation id on the ToolInvoked event', function (): void {
    $parameterNames = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod(ToolInvoked::class, '__construct'))->getParameters());

    expect(in_array('invocationId', $parameterNames, true))->toBeTrue();
});
