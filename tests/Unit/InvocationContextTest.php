<?php

declare(strict_types=1);

use Fissible\Verdict\LaravelAi\InvocationContext;

it('reports no invocation when nothing is in scope', function (): void {
    expect((new InvocationContext)->current())->toBeNull();
});

it('reports the invocation in scope for the duration of the callback', function (): void {
    $context = new InvocationContext;

    $seen = $context->within('invocation-one', fn (): ?string => $context->current());

    expect($seen)->toBe('invocation-one')
        ->and($context->current())->toBeNull();
});

it('returns the callback result', function (): void {
    expect((new InvocationContext)->within('invocation-one', fn (): string => 'result'))->toBe('result');
});

it('restores the enclosing invocation when a nested one completes', function (): void {
    // A tool may start a nested generation while it runs; AgentTool does exactly this when it
    // prompts a sub-agent. The outer invocation must still be in scope once the inner one unwinds.
    $context = new InvocationContext;

    $trace = [];

    $context->within('outer', function () use ($context, &$trace): void {
        $trace[] = $context->current();

        $context->within('inner', function () use ($context, &$trace): void {
            $trace[] = $context->current();
        });

        $trace[] = $context->current();
    });

    expect($trace)->toBe(['outer', 'inner', 'outer'])
        ->and($context->current())->toBeNull();
});

it('pops the frame when the callback throws', function (): void {
    $context = new InvocationContext;

    expect(fn (): mixed => $context->within('invocation-one', function (): void {
        throw new RuntimeException('tool failed');
    }))->toThrow(RuntimeException::class);

    expect($context->current())->toBeNull();
});

it('rejects an invocation id that is not a valid identifier', function (): void {
    // Reuses ProvenanceEntry::assertIdentifier rather than introducing a second convention.
    expect(fn (): mixed => (new InvocationContext)->within('not a valid id', fn (): null => null))
        ->toThrow(InvalidArgumentException::class);
});
