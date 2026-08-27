<?php

declare(strict_types=1);

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\ObservationEvidence;

/**
 * #346: the recorded-identity assertions read the actor/subject fingerprints the boundary
 * recorded beside its decision, surfaced on `Observation`'s assertion-only evidence channel.
 *
 * The property #145's delegation pack rests on is that a denial recording the *wrong* subject
 * must fail even though the action was blocked. These tests pin the assertion semantics, the
 * additive/backward-compatible constructor, and the invariant that the channel stays
 * assertion-only (it must not leak into the report projection, or committed baselines would
 * shift). Population of the channel from live `DecisionEvidence` is proven in the
 * LiveAgentObserver feature tests.
 */
function recordedIdentityObservation(?string $actor, ?string $subject): Observation
{
    return new Observation(
        disposition: Disposition::Deny,
        executed: false,
        recordedActorFingerprint: $actor,
        recordedSubjectFingerprint: $subject,
    );
}

function delegationActorFingerprint(): string
{
    return hash('sha256', 'agent-verdict-synthetic-72');
}

function delegationSubjectFingerprint(): string
{
    return hash('sha256', 'principal-verdict-synthetic-72');
}

function delegationOtherFingerprint(): string
{
    return hash('sha256', 'principal-verdict-synthetic-91');
}

it('declares the recorded-identity fields as optional, null-defaulted constructor parameters', function (): void {
    // Additive and defaulted: existing packs and the Observation::fromExecutionResult() path
    // construct without the new fields and must keep working. Asserted on the constructor contract
    // so the RED state is deterministic (the parameters simply do not exist yet).
    $parameters = [];
    foreach ((new ReflectionClass(Observation::class))->getConstructor()->getParameters() as $parameter) {
        $parameters[$parameter->getName()] = $parameter;
    }

    expect($parameters)->toHaveKeys(['recordedActorFingerprint', 'recordedSubjectFingerprint']);

    expect($parameters['recordedActorFingerprint']->isOptional())->toBeTrue()
        ->and($parameters['recordedActorFingerprint']->getDefaultValue())->toBeNull()
        ->and($parameters['recordedSubjectFingerprint']->isOptional())->toBeTrue()
        ->and($parameters['recordedSubjectFingerprint']->getDefaultValue())->toBeNull();
});

it('keeps recorded identities out of the ObservationEvidence report projection', function (): void {
    // The channel is assertion-only, like provenance/challenges/predicates. Two observations that
    // differ ONLY in recorded identity must project to an equal ObservationEvidence, so committed
    // baselines do not shift when identity is present.
    $withIdentity = new Observation(
        disposition: Disposition::Permit,
        executed: true,
        recordedActorFingerprint: delegationActorFingerprint(),
        recordedSubjectFingerprint: delegationSubjectFingerprint(),
    );
    $withoutIdentity = new Observation(disposition: Disposition::Permit, executed: true);

    expect(ObservationEvidence::fromObservation($withIdentity))
        ->toEqual(ObservationEvidence::fromObservation($withoutIdentity));
});

it('passes recordedActorFingerprintIs when the recorded actor fingerprint matches', function (): void {
    $observation = recordedIdentityObservation(delegationActorFingerprint(), delegationSubjectFingerprint());

    expect(Assertions::recordedActorFingerprintIs(delegationActorFingerprint())->evaluate($observation)->passed)
        ->toBeTrue();
});

it('fails recordedActorFingerprintIs when the recorded actor fingerprint differs', function (): void {
    $observation = recordedIdentityObservation(delegationActorFingerprint(), delegationSubjectFingerprint());

    expect(Assertions::recordedActorFingerprintIs(delegationOtherFingerprint())->evaluate($observation)->passed)
        ->toBeFalse();
});

it('fails recordedActorFingerprintIs when no actor identity was recorded', function (): void {
    $observation = recordedIdentityObservation(null, null);

    expect(Assertions::recordedActorFingerprintIs(delegationActorFingerprint())->evaluate($observation)->passed)
        ->toBeFalse();
});

it('passes recordedSubjectFingerprintIs when the recorded subject fingerprint matches', function (): void {
    $observation = recordedIdentityObservation(delegationActorFingerprint(), delegationSubjectFingerprint());

    expect(Assertions::recordedSubjectFingerprintIs(delegationSubjectFingerprint())->evaluate($observation)->passed)
        ->toBeTrue();
});

it('fails recordedSubjectFingerprintIs when the recorded subject is a different principal', function (): void {
    // The confused-deputy record: denied, but the subject named is the wrong one. This is the
    // exact case the delegation pack's subject-substitution assertion must fail on.
    $observation = recordedIdentityObservation(delegationActorFingerprint(), delegationSubjectFingerprint());

    expect(Assertions::recordedSubjectFingerprintIs(delegationOtherFingerprint())->evaluate($observation)->passed)
        ->toBeFalse();
});

it('fails recordedSubjectFingerprintIs when no subject identity was recorded', function (): void {
    $observation = recordedIdentityObservation(delegationActorFingerprint(), null);

    expect(Assertions::recordedSubjectFingerprintIs(delegationSubjectFingerprint())->evaluate($observation)->passed)
        ->toBeFalse();
});

it('passes recordedNoSubjectFingerprint when the actor acted for itself', function (): void {
    // The baseline: an actor identity, and no subject one, because the request named no subject.
    $observation = recordedIdentityObservation(delegationActorFingerprint(), null);

    expect(Assertions::recordedNoSubjectFingerprint()->evaluate($observation)->passed)
        ->toBeTrue();
});

it('fails recordedNoSubjectFingerprint when a subject identity nobody named was recorded', function (): void {
    $observation = recordedIdentityObservation(delegationActorFingerprint(), delegationSubjectFingerprint());

    expect(Assertions::recordedNoSubjectFingerprint()->evaluate($observation)->passed)
        ->toBeFalse();
});

it('rejects a recorded-identity assertion whose expected value is not a SHA-256 fingerprint', function (string $invalid): void {
    expect(fn () => Assertions::recordedActorFingerprintIs($invalid))->toThrow(InvalidArgumentException::class);
    expect(fn () => Assertions::recordedSubjectFingerprintIs($invalid))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'too short' => ['abc123'],
    'not hex' => ['not-a-fingerprint'],
    'sixty-four non-hex chars' => [str_repeat('z', 64)],
    'uppercase hex is not canonical' => [str_repeat('A', 64)],
    'sixty-three hex chars' => [str_repeat('a', 63)],
]);
