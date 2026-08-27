<?php

declare(strict_types=1);

/**
 * @contract-behaviour approval-pause
 *
 * @contract-fidelity constructed
 *
 * @contract-consequence Verdict's confirmation gate depends on Laravel AI representing a required approval as an Approval object.
 */
use Laravel\Ai\Approvals\Approval;

it('represents a required approval as a non-null approval object', function (): void {
    expect(Approval::required('Confirm cancellation.')->reason)->toBe('Confirm cancellation.');
});
