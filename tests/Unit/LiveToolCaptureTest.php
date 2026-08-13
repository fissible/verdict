<?php

declare(strict_types=1);

use Fissible\Verdict\Decisions\Disposition;
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
