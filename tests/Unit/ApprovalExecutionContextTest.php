<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

it('allows a tool call after push and denies it after pop', function (): void {
    $context = new ApprovalExecutionContext;
    $decisions = Decisions::from(['call-1' => Decision::approve()]);

    expect($context->allows('call-1'))->toBeFalse();

    $context->push($decisions);

    expect($context->allows('call-1'))->toBeTrue()
        ->and($context->allows('call-unrelated'))->toBeFalse();

    $context->pop();

    expect($context->allows('call-1'))->toBeFalse();
});

it('does not treat a rejected or wildcard decision as an approval', function (): void {
    $context = new ApprovalExecutionContext;
    $decisions = Decisions::from([
        'call-rejected' => Decision::reject(),
        '*' => Decision::approve(),
    ]);

    $context->push($decisions);

    expect($context->allows('call-rejected'))->toBeFalse()
        ->and($context->allows('*'))->toBeFalse();

    $context->pop();
});

it('supports nested frames, most recent first', function (): void {
    $context = new ApprovalExecutionContext;

    $context->push(Decisions::from(['call-outer' => Decision::approve()]));
    $context->push(Decisions::from(['call-inner' => Decision::approve()]));

    expect($context->allows('call-inner'))->toBeTrue()
        ->and($context->allows('call-outer'))->toBeFalse();

    $context->pop();

    expect($context->allows('call-outer'))->toBeTrue()
        ->and($context->allows('call-inner'))->toBeFalse();

    $context->pop();
});

it('still supports within() as a synchronous push-then-pop-in-finally convenience', function (): void {
    $context = new ApprovalExecutionContext;
    $decisions = Decisions::from(['call-1' => Decision::approve()]);
    $seenInsideCallback = null;

    $result = $context->within($decisions, function () use ($context, &$seenInsideCallback): string {
        $seenInsideCallback = $context->allows('call-1');

        return 'callback result';
    });

    expect($seenInsideCallback)->toBeTrue()
        ->and($result)->toBe('callback result')
        ->and($context->allows('call-1'))->toBeFalse();
});

it('pops within()\'s frame even when the callback throws', function (): void {
    $context = new ApprovalExecutionContext;
    $decisions = Decisions::from(['call-1' => Decision::approve()]);

    expect(fn () => $context->within($decisions, function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect($context->allows('call-1'))->toBeFalse();
});
