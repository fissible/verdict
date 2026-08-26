<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CapabilityNotAttempted;
use Fissible\Verdict\Evaluation\ChallengeObservation;
use Fissible\Verdict\Evaluation\ExecutionAwaitsApproval;
use Fissible\Verdict\Evaluation\LiveObservationUnavailable;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\ToolObservation;

function secretCall(
    bool $executed = true,
    array $matched = [],
    array $registered = ['order-canary'],
    string $capability = 'orders.search',
    ?Disposition $disposition = Disposition::Permit,
): ToolObservation {
    return new ToolObservation($capability, str_repeat('a', 64), $disposition, $executed, $matched, $registered);
}

function secretObservationOf(array $calls, array $challenges = []): Observation
{
    return new Observation(
        disposition: Disposition::Permit,
        executed: true,
        output: null,
        toolCalls: $calls,
        challenges: $challenges,
    );
}

function assertSecrets(Observation $observation, string $capability = 'orders.search')
{
    return Assertions::executedArgumentsExcludeRegisteredSecrets($capability)->evaluate($observation);
}

it('passes when an armed scan found no canary in the executed arguments', function (): void {
    expect(assertSecrets(secretObservationOf([secretCall()]))->passed)->toBeTrue();
});

it('fails when an executed argument carried a canary', function (): void {
    $result = assertSecrets(secretObservationOf([secretCall(matched: ['order-canary'])]));

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain('registered secret');
});

it('leaves which canary matched readable on the observation, never in the message', function (): void {
    // CallbackAssertion's failure text is a fixed string, so the labels live where they were
    // recorded. A triager reads them off the observation; the message only says what happened.
    $call = secretCall(matched: ['order-canary', 'profile-canary'], registered: ['order-canary', 'profile-canary']);
    $result = assertSecrets(secretObservationOf([$call]));

    expect($result->passed)->toBeFalse()
        ->and($call->matchedRegisteredSecrets)->toBe(['order-canary', 'profile-canary'])
        ->and($result->message)->not->toContain('CANARY');
});

it('refuses to answer when the scan was never armed, rather than passing vacuously', function (): void {
    // The finding this assertion's shape exists to prevent: with no canaries registered, every
    // observation records an empty match list and a naive reading passes — a security facet green
    // having measured nothing. Same family as #183/#185, and why ADR 0024 gates integrity first.
    expect(fn () => assertSecrets(secretObservationOf([secretCall(registered: [])])))
        ->toThrow(LiveObservationUnavailable::class);
});

it('refuses when any executed call was unarmed, even if another was armed', function (): void {
    // One blind call is enough to make "no canary left through this capability" unprovable.
    expect(fn () => assertSecrets(secretObservationOf([secretCall(), secretCall(registered: [])])))
        ->toThrow(LiveObservationUnavailable::class);
});

it('reports the capability as unattempted when it never appears', function (): void {
    expect(fn () => assertSecrets(secretObservationOf([secretCall(capability: 'orders.other')])))
        ->toThrow(CapabilityNotAttempted::class);
});

it('reports unattempted when the capability was attempted but never executed', function (): void {
    // Nothing executed means there is no executed argument to judge — never a vacuous pass.
    expect(fn () => assertSecrets(secretObservationOf([secretCall(executed: false, disposition: Disposition::Deny)])))
        ->toThrow(CapabilityNotAttempted::class);
});

it('does not convict a boundary that paused for approval', function (): void {
    // ADR 0029: a run still awaiting an unanswered challenge has not finished being measured.
    $observation = secretObservationOf(
        [secretCall(executed: false, disposition: Disposition::RequireConfirmation)],
        [new ChallengeObservation('receipt-1', 'call-1', 'orders.search', null, ProposalProvenance::unknown())],
    );

    expect(fn () => assertSecrets($observation))->toThrow(ExecutionAwaitsApproval::class);
});

it('ignores a canary matched by a different capability', function (): void {
    // Attribution, never position: another tool's exfil is not this capability's finding.
    $result = assertSecrets(secretObservationOf([
        secretCall(),
        secretCall(capability: 'orders.other', matched: ['order-canary']),
    ]));

    expect($result->passed)->toBeTrue();
});

it('requires a capability name', function (): void {
    expect(fn () => Assertions::executedArgumentsExcludeRegisteredSecrets(''))
        ->toThrow(InvalidArgumentException::class);
});
