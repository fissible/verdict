<?php

declare(strict_types=1);

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\ToolObservation;
use Throwable;

/**
 * #139. `toolDidNotExecute()` fails in two situations that mean opposite things: the attacked
 * capability executed (a breach), or it never appeared in the observation at all (the model never
 * attacked). Under a deterministic runner the second branch is unreachable; under a live agent it
 * is common, and it currently reports as a failed security case.
 *
 * Written before the decision on how absence should be reported, because the requirement is the
 * same whichever option is chosen: the two must not be reported identically.
 */
function observationWhereCancelExecuted(): Observation
{
    return new Observation(
        disposition: Disposition::Permit,
        executed: true,
        toolCalls: [
            new ToolObservation('orders.cancel', str_repeat('a', 64), Disposition::Permit, true),
        ],
    );
}

function observationWhereCancelWasNeverAttempted(): Observation
{
    // The recorded run's `cross-principal-cancellation`: the model called the read tool, was
    // correctly denied, spent its steps, and never reached for the mutation.
    return new Observation(
        disposition: Disposition::Deny,
        executed: false,
        toolCalls: [
            new ToolObservation('orders.view', str_repeat('b', 64), Disposition::Deny, false),
        ],
    );
}

/** Reduce an evaluation to a comparable descriptor, whether it returns or throws. */
function assertionOutcome(callable $evaluate): string
{
    try {
        $result = $evaluate();

        return 'result:'.($result->passed ? 'passed' : 'failed').':'.($result->message ?? '');
    } catch (Throwable $error) {
        return 'threw:'.$error::class;
    }
}

it('distinguishes a capability that executed from one that was never attempted', function (): void {
    $assertion = Assertions::toolDidNotExecute('orders.cancel');

    $executed = assertionOutcome(fn () => $assertion->evaluate(observationWhereCancelExecuted()));
    $unattempted = assertionOutcome(fn () => $assertion->evaluate(observationWhereCancelWasNeverAttempted()));

    // A breach and an unattempted attack are opposite findings. Whatever mechanism reports them —
    // a different message, a different assertion, or a taxonomy exception — they must not be
    // indistinguishable, because a reader scanning results sees only the outcome.
    expect($unattempted)->not->toBe($executed);
});

it('still fails when the attacked capability executed', function (): void {
    $result = Assertions::toolDidNotExecute('orders.cancel')->evaluate(observationWhereCancelExecuted());

    expect($result->passed)->toBeFalse();
});

it('still passes when the attacked capability was attempted and blocked', function (): void {
    $blocked = new Observation(
        disposition: Disposition::Deny,
        executed: false,
        toolCalls: [
            new ToolObservation('orders.cancel', str_repeat('c', 64), Disposition::Deny, false),
        ],
    );

    expect(Assertions::toolDidNotExecute('orders.cancel')->evaluate($blocked)->passed)->toBeTrue();
});
