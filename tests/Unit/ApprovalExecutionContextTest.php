<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovedToolCalls;

/**
 * Rewritten for ADR 0033 §2: this kernel type now takes `ApprovedToolCalls` rather than
 * `Laravel\Ai\Approvals\Decisions`. The frame behaviour it pins is unchanged — only the vocabulary
 * crossing the boundary is. The wildcard and rejected-decision exclusions that used to be asserted
 * here now belong to the adapter's translation, and are pinned in
 * `tests/Feature/ApprovalDecisionTranslationTest.php`.
 */
it('types its frame API to the Verdict value object, closing the duck-typing route', function (): void {
    // Source-level absence of `Laravel\Ai` is not enough: a signature typed `mixed` or `object`
    // could still accept an upstream Decisions and interpret it, leaving the filtering
    // semantically in the kernel while naming nothing. Declared parameter types are what make the
    // translation provably the adapter's job.
    foreach ([[ApprovalExecutionContext::class, 'push', 0], [ApprovalExecutionContext::class, 'within', 0]] as [$class, $method, $position]) {
        $type = (new ReflectionMethod($class, $method))->getParameters()[$position]->getType();

        expect($type)->toBeInstanceOf(ReflectionNamedType::class, "{$class}::{$method}() must declare its first parameter type.")
            ->and($type->getName())->toBe(ApprovedToolCalls::class)
            ->and($type->allowsNull())->toBeFalse();
    }
});

it('does not name an upstream type', function (): void {
    $source = (string) file_get_contents((new ReflectionClass(ApprovalExecutionContext::class))->getFileName());

    expect($source)->not->toContain('Laravel\\Ai');
});

it('allows a tool call after push and denies it after pop', function (): void {
    $context = new ApprovalExecutionContext;

    expect($context->allows('call-1'))->toBeFalse();

    $context->push(ApprovedToolCalls::of(['call-1']));

    expect($context->allows('call-1'))->toBeTrue()
        ->and($context->allows('call-unrelated'))->toBeFalse();

    $context->pop();

    expect($context->allows('call-1'))->toBeFalse();
});

it('supports nested frames, most recent first', function (): void {
    $context = new ApprovalExecutionContext;

    $context->push(ApprovedToolCalls::of(['call-outer']));
    $context->push(ApprovedToolCalls::of(['call-inner']));

    expect($context->allows('call-inner'))->toBeTrue()
        ->and($context->allows('call-outer'))->toBeFalse();

    $context->pop();

    expect($context->allows('call-outer'))->toBeTrue()
        ->and($context->allows('call-inner'))->toBeFalse();

    $context->pop();
});

it('still supports within() as a synchronous push-then-pop-in-finally convenience', function (): void {
    $context = new ApprovalExecutionContext;
    $seenInsideCallback = null;

    $result = $context->within(ApprovedToolCalls::of(['call-1']), function () use ($context, &$seenInsideCallback): string {
        $seenInsideCallback = $context->allows('call-1');

        return 'callback result';
    });

    expect($seenInsideCallback)->toBeTrue()
        ->and($result)->toBe('callback result')
        ->and($context->allows('call-1'))->toBeFalse();
});

it('pops within()\'s frame even when the callback throws', function (): void {
    $context = new ApprovalExecutionContext;

    expect(fn () => $context->within(ApprovedToolCalls::of(['call-1']), function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect($context->allows('call-1'))->toBeFalse();
});
