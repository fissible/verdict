<?php

declare(strict_types=1);

/**
 * @contract-behaviour step-text-gateway
 *
 * @contract-fidelity constructed
 *
 * @contract-consequence Verdict's streamed and queued approval-resumption fixtures need this gateway seam to drive controlled Laravel AI output.
 */
use Laravel\Ai\Contracts\Gateway\StepTextGateway;

it('keeps StepTextGateway available as the controlled streaming test seam', function (): void {
    expect(interface_exists(StepTextGateway::class))->toBeTrue();
});
