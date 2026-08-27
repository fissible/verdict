<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovedToolCalls;
use Laravel\Ai\Approvals\Decision;

/**
 * The kernel's entire dependency on laravel/ai's approval vocabulary, per ADR 0033 §2: the set of
 * tool-call ids a human approved. `ApprovalExecutionContext::push()` used to derive this from
 * `Laravel\Ai\Approvals\Decisions` inside the kernel; the adapter now derives it and hands this
 * type across, so an upstream change to `Decisions` cannot reach the security core.
 */
it('is reachable without laravel/ai in scope', function (): void {
    // The point of the type. If this class names an upstream symbol, the translation has simply
    // moved rather than happened, and ADR 0033's boundary buys nothing here.
    $source = (string) file_get_contents((new ReflectionClass(ApprovedToolCalls::class))->getFileName());

    expect($source)->not->toContain('Laravel\\Ai');
});

it('reports whether a tool call was approved', function (): void {
    $approved = ApprovedToolCalls::of(['call-1', 'call-2']);

    expect($approved->allows('call-1'))->toBeTrue()
        ->and($approved->allows('call-2'))->toBeTrue()
        ->and($approved->allows('call-3'))->toBeFalse();
});

it('allows nothing when empty', function (): void {
    expect(ApprovedToolCalls::of([])->allows('call-1'))->toBeFalse();
});

it('refuses the wildcard, which is not a tool-call id', function (): void {
    // The security-bearing invariant. Upstream's `Decisions` may carry a '*' key meaning "approve
    // everything"; `push()` skipped it. If it ever arrives here as an id, some caller has confused
    // a blanket approval for a specific one, and treating it as an id would authorize a call
    // nobody approved. Refuse loudly rather than silently drop it: a silent drop leaves the caller
    // believing a blanket approval was honoured.
    expect(fn () => ApprovedToolCalls::of(['*']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ApprovedToolCalls::of(['call-1', '*']))->toThrow(InvalidArgumentException::class);
});

it('refuses anything that is not a string id', function (): void {
    // Closes the duck-typing hole: an implementation that accepted upstream Decision objects and
    // called isApproved() on them would name no upstream symbol, pass the source-boundary check,
    // and leave the filtering semantically in the kernel — the exact thing ADR 0033 moved out.
    // A list<string> boundary is what makes the translation provably the adapter's job.
    expect(fn () => ApprovedToolCalls::of([Decision::approve()]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ApprovedToolCalls::of([123]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ApprovedToolCalls::of([null]))->toThrow(InvalidArgumentException::class);
});

it('refuses a blank id', function (): void {
    expect(fn () => ApprovedToolCalls::of(['']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ApprovedToolCalls::of(['   ']))->toThrow(InvalidArgumentException::class);
});
