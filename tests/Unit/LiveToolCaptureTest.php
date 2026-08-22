<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\ChallengeObservation;
use Fissible\Verdict\Evaluation\LiveToolCapture;

it('records one tool observation per bound-tool call and resets between invocations', function (): void {
    $capture = new LiveToolCapture;
    expect($capture->isEmpty())->toBeTrue();

    $capture->record('orders.read', str_repeat('a', 64), Disposition::Permit, true);
    $capture->record('orders.cancel', str_repeat('b', 64), Disposition::Deny, false);

    expect($capture->isEmpty())->toBeFalse()
        ->and($capture->toolObservations())->toHaveCount(2)
        ->and($capture->toolObservations()[1]->capability)->toBe('orders.cancel')
        ->and($capture->toolObservations()[1]->executed)->toBeFalse();

    $capture->reset();

    expect($capture->isEmpty())->toBeTrue()
        ->and($capture->toolObservations())->toBe([]);
});

it('records domain side effects independently of tool observations', function (): void {
    $capture = new LiveToolCapture;

    $capture->recordSideEffect('order.cancelled');
    $capture->recordSideEffect('refund.issued');

    expect($capture->sideEffects())->toBe(['order.cancelled', 'refund.issued']);

    $capture->reset();

    expect($capture->sideEffects())->toBe([]);
});

it('records challenges and the preflight invocation id, and reset clears them', function (): void {
    $capture = new LiveToolCapture;
    $challenge = new ChallengeObservation(
        receiptId: str_repeat('r', 64),
        toolCallId: 'call-capture-1',
        capability: 'payments.transfer',
        reason: null,
        provenance: ProposalProvenance::unknown(),
    );

    $capture->recordChallenge($challenge);
    $capture->recordInvocationId('invocation-capture');

    expect($capture->challenges())->toBe([$challenge])
        ->and($capture->invocationId())->toBe('invocation-capture');

    $capture->reset();

    expect($capture->challenges())->toBe([])
        ->and($capture->invocationId())->toBeNull();
});

it('orders handle-path records before preflight attempts, and counts both as non-empty', function (): void {
    $capture = new LiveToolCapture;

    // The order the harness writes them in is the order laravel/ai runs them: every tool call's
    // approval preflight fires before any tool in the step executes. Execution order is the
    // reverse, because the pause the preflight caused is the step's terminal outcome.
    $capture->recordPreflightAttempt('orders.cancel', str_repeat('b', 64), Disposition::RequireConfirmation, false);
    $capture->record('orders.view', str_repeat('a', 64), Disposition::Permit, true);

    expect($capture->toolObservations())->toHaveCount(2)
        ->and($capture->toolObservations()[0]->capability)->toBe('orders.view')
        ->and($capture->toolObservations()[1]->capability)->toBe('orders.cancel');

    $capture->reset();

    expect($capture->toolObservations())->toBe([])
        ->and($capture->isEmpty())->toBeTrue();
});

it('does not read a preflight-only capture as empty', function (): void {
    $capture = new LiveToolCapture;

    // A run that paused before any tool could execute captured an attempt, not nothing: reading
    // this as empty would report a gate that fired as ModelDeclinedToAct.
    $capture->recordPreflightAttempt('orders.cancel', str_repeat('b', 64), Disposition::RequireConfirmation, false);

    expect($capture->isEmpty())->toBeFalse()
        ->and($capture->toolObservations())->toHaveCount(1);
});
