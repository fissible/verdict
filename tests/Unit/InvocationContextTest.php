<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\LaravelAi\InvocationContext;

/** @param array<string, mixed> $arguments */
function preparedEnvelope(array $arguments): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal('operations.test', $arguments, 'call-shared'),
        context: new ActionContext('customer-72'),
    );
}

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

it('does not expose an outer prepared envelope to a nested invocation with the same tool call id', function (): void {
    $context = new InvocationContext;
    $envelope = preparedEnvelope(['operation_id' => 1001]);

    $context->within('outer', function () use ($context, $envelope): void {
        $context->rememberPreparedEnvelope('call-shared', ['operation_id' => 1001], $envelope);

        $context->within('inner', function () use ($context): void {
            expect($context->takePreparedEnvelope('call-shared', ['operation_id' => 1001]))->toBeNull();
        });

        expect($context->takePreparedEnvelope('call-shared', ['operation_id' => 1001]))->toBe($envelope);
    });
});

it('retains an outer prepared envelope while an inner frame shares its invocation id', function (): void {
    $context = new InvocationContext;
    $envelope = preparedEnvelope(['operation_id' => 1001]);

    $context->within('shared-invocation', function () use ($context, $envelope): void {
        $context->rememberPreparedEnvelope('call-shared', ['operation_id' => 1001], $envelope);

        $context->within('shared-invocation', function (): void {
            // The middleware's synchronous and lazy stream frames legitimately share this ID.
        });

        expect($context->takePreparedEnvelope('call-shared', ['operation_id' => 1001]))->toBe($envelope);
    });
});

it('discards a prepared envelope after it is consumed or its arguments no longer match', function (): void {
    $context = new InvocationContext;
    $envelope = preparedEnvelope(['operation_id' => 1001]);

    $context->within('invocation-one', function () use ($context, $envelope): void {
        $context->rememberPreparedEnvelope('call-shared', ['operation_id' => 1001], $envelope);

        expect($context->takePreparedEnvelope('call-shared', ['operation_id' => 1002]))->toBeNull()
            ->and($context->takePreparedEnvelope('call-shared', ['operation_id' => 1001]))->toBeNull();
    });
});

it('does not retain a prepared envelope after an invocation frame unwinds', function (): void {
    $context = new InvocationContext;
    $envelope = preparedEnvelope(['operation_id' => 1001]);

    $context->within('abandoned-stream', function () use ($context, $envelope): void {
        $context->rememberPreparedEnvelope('call-shared', ['operation_id' => 1001], $envelope);
    });

    // This simulates a provider reusing its tool-call and invocation identifiers after an
    // abandoned stream has unwound. The frame pop, not identifier uniqueness, prevents a hit.
    $context->within('abandoned-stream', function () use ($context): void {
        expect($context->takePreparedEnvelope('call-shared', ['operation_id' => 1001]))->toBeNull();
    });
});
