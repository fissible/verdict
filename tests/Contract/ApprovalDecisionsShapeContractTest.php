<?php

declare(strict_types=1);

/**
 * @contract-behaviour approval-decisions-shape
 *
 * @contract-fidelity constructed
 *
 * @contract-consequence The kernel translation must retain only explicit approved tool-call ids and reject Laravel AI's wildcard.
 */
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

it('returns id-keyed decisions including Laravel AI wildcard decisions', function (): void {
    $all = Decisions::from(['call-1' => Decision::approve(), '*' => Decision::reject()])->all();

    expect(array_keys($all))->toBe(['call-1', '*'])
        ->and($all['call-1']->isApproved())->toBeTrue()
        ->and($all['*']->isApproved())->toBeFalse();
});
