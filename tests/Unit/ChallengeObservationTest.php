<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Approvals\ProvenanceDisclosure;
use Fissible\Verdict\Approvals\UpstreamSource;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CapabilityNotAttempted;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\ChallengeObservation;
use Fissible\Verdict\Evaluation\EvaluationCase;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\ObservationEvidence;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\ToolObservation;

function containmentChallenge(): ChallengeObservation
{
    return new ChallengeObservation(
        receiptId: str_repeat('r', 64),
        toolCallId: 'call-containment-1',
        capability: 'payments.transfer',
        reason: 'Confirm this transfer.',
        provenance: ProposalProvenance::unknown(),
    );
}

it('validates challenge observations on construction', function (): void {
    expect(fn () => new ChallengeObservation('', 'call-1', 'payments.transfer', null, ProposalProvenance::unknown()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new Observation(disposition: null, executed: false, challenges: ['not-a-challenge']))
        ->toThrow(InvalidArgumentException::class);
});

it('builds from a challenge with decision null and refuses a payloadless one', function (): void {
    $challenge = new ApprovalChallenge(
        receiptId: str_repeat('r', 64),
        toolCallId: 'call-containment-1',
        capability: 'payments.transfer',
        reason: 'Confirm this transfer.',
        expiresAt: new DateTimeImmutable('2026-08-08 12:15:00'),
        provenance: ProposalProvenance::unknown(),
    );

    $observation = ChallengeObservation::fromChallenge($challenge);
    expect($observation->decision)->toBeNull()
        ->and($observation->capability)->toBe('payments.transfer');

    $payloadless = new ApprovalChallenge(
        receiptId: str_repeat('r', 64),
        toolCallId: 'call-containment-2',
        capability: 'payments.transfer',
        reason: null,
        expiresAt: new DateTimeImmutable('2026-08-08 12:15:00'),
        provenance: null,
    );
    expect(fn () => ChallengeObservation::fromChallenge($payloadless))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * Pins ADR 0029 decision 2: challenge facts are assertion-only. The provenance-entries
 * precedent holds today only because nobody added a serializer; this pin makes the rule
 * enforced rather than accidental (the #247 lesson).
 */
it('drops challenge facts from observation evidence and from the report round-trip', function (): void {
    $observation = new Observation(
        disposition: Disposition::RequireConfirmation,
        executed: false,
        toolCalls: [new ToolObservation('payments.transfer', str_repeat('a', 64), Disposition::RequireConfirmation, false)],
        challenges: [containmentChallenge()],
    );

    $evidence = ObservationEvidence::fromObservation($observation);
    expect(json_encode(get_object_vars($evidence), JSON_THROW_ON_ERROR))->not->toContain('challenge');

    $suite = new SecuritySuite('containment-verify-suite', '1', [
        EvaluationCase::attack(
            id: 'containment-case',
            version: '1',
            input: new CaseInput(trustedSetup: [], untrustedInput: ['request' => 'noop']),
            runner: fn (CaseInput $input): Observation => $observation,
            assertions: [Assertions::decisionIs(Disposition::RequireConfirmation)],
        ),
    ]);

    expect(json_encode($suite->run()->report()->toArray(), JSON_THROW_ON_ERROR))->not->toContain('challenge');
});

/**
 * Task 8: challenge predicates over the approver payload. Each predicate shares the same
 * three-outcome shape as `toolAttemptedButBlocked()` (ADR 0029): a challenge for the capability
 * exists (evaluate the payload condition), the capability was attempted but no challenge exists
 * (a measured negative — the gate did not fire, so the assertion returns false rather than
 * throwing), or the capability appears in neither `toolCalls` nor `challenges` (unmeasured, so
 * `CapabilityNotAttempted` is thrown per ADR 0021/0022).
 */
function challengeWithProvenance(ProposalProvenance $provenance, string $capability = 'payments.transfer'): ChallengeObservation
{
    return new ChallengeObservation(
        receiptId: str_repeat('r', 64),
        toolCallId: 'call-containment-1',
        capability: $capability,
        reason: 'Confirm this transfer.',
        provenance: $provenance,
    );
}

function observationWithChallenge(ChallengeObservation $challenge, string $capability = 'payments.transfer'): Observation
{
    return new Observation(
        disposition: Disposition::RequireConfirmation,
        executed: false,
        toolCalls: [new ToolObservation($capability, str_repeat('a', 64), Disposition::RequireConfirmation, false)],
        challenges: [$challenge],
    );
}

/** Capability was attempted (appears in `toolCalls`) but no matching challenge was issued. */
function observationWithAttemptButNoChallenge(string $capability = 'payments.transfer'): Observation
{
    return new Observation(
        disposition: Disposition::Permit,
        executed: true,
        toolCalls: [new ToolObservation($capability, str_repeat('a', 64), Disposition::Permit, true)],
        challenges: [],
    );
}

/** Capability appears in neither `toolCalls` nor `challenges`. */
function observationWhereCapabilityWasNeverAttempted(): Observation
{
    return new Observation(
        disposition: Disposition::Deny,
        executed: false,
        toolCalls: [new ToolObservation('orders.view', str_repeat('b', 64), Disposition::Deny, false)],
        challenges: [],
    );
}

function supportTicketUpstream(Trust $trust = Trust::Untrusted, ContextChannel $channel = ContextChannel::RetrievedDocument): UpstreamSource
{
    return new UpstreamSource(
        source: Source::external('support-ticket-index'),
        trust: $trust,
        dataClass: DataClass::Internal,
        channel: $channel,
    );
}

it('passes challengeIssuedFor when a challenge for the capability exists', function (): void {
    $observation = observationWithChallenge(challengeWithProvenance(ProposalProvenance::unknown()));

    $result = Assertions::challengeIssuedFor('payments.transfer')->evaluate($observation);

    expect($result->passed)->toBeTrue()
        ->and($result->assertion)->toBe('challenge_issued_for');
});

it('fails challengeIssuedFor when the capability was attempted but no challenge exists', function (): void {
    $result = Assertions::challengeIssuedFor('payments.transfer')
        ->evaluate(observationWithAttemptButNoChallenge());

    expect($result->passed)->toBeFalse();
});

it('throws CapabilityNotAttempted for challengeIssuedFor when the capability appears nowhere in the observation', function (): void {
    expect(fn () => Assertions::challengeIssuedFor('payments.transfer')
        ->evaluate(observationWhereCapabilityWasNeverAttempted()))
        ->toThrow(CapabilityNotAttempted::class);
});

it('passes challengeDisclosureIs when the challenge disclosure matches, including Unreleased', function (): void {
    // "The approver was shown nothing" is itself assertable — ADR 0029 decision 2.
    $observation = observationWithChallenge(challengeWithProvenance(ProposalProvenance::unreleased()));

    $result = Assertions::challengeDisclosureIs('payments.transfer', ProvenanceDisclosure::Unreleased)
        ->evaluate($observation);

    expect($result->passed)->toBeTrue()
        ->and($result->assertion)->toBe('challenge_disclosure_is');
});

it('fails challengeDisclosureIs when the challenge disclosure does not match', function (): void {
    $observation = observationWithChallenge(challengeWithProvenance(ProposalProvenance::unknown()));

    $result = Assertions::challengeDisclosureIs('payments.transfer', ProvenanceDisclosure::Unreleased)
        ->evaluate($observation);

    expect($result->passed)->toBeFalse();
});

it('throws CapabilityNotAttempted for challengeDisclosureIs when the capability appears nowhere in the observation', function (): void {
    expect(fn () => Assertions::challengeDisclosureIs('payments.transfer', ProvenanceDisclosure::Unreleased)
        ->evaluate(observationWhereCapabilityWasNeverAttempted()))
        ->toThrow(CapabilityNotAttempted::class);
});

it('passes challengeDisclosesDeclaredUpstream when a declared source matches identity, trust, and channel', function (): void {
    $observation = observationWithChallenge(challengeWithProvenance(
        ProposalProvenance::declared([supportTicketUpstream()])
    ));

    $result = Assertions::challengeDisclosesDeclaredUpstream(
        'payments.transfer',
        'external:support-ticket-index',
        Trust::Untrusted,
        ContextChannel::RetrievedDocument,
    )->evaluate($observation);

    expect($result->passed)->toBeTrue()
        ->and($result->assertion)->toBe('challenge_discloses_declared_upstream');
});

it('fails challengeDisclosesDeclaredUpstream when the declared source identity does not match', function (): void {
    $observation = observationWithChallenge(challengeWithProvenance(
        ProposalProvenance::declared([supportTicketUpstream()])
    ));

    $result = Assertions::challengeDisclosesDeclaredUpstream(
        'payments.transfer',
        'external:some-other-source',
    )->evaluate($observation);

    expect($result->passed)->toBeFalse();
});

it('fails challengeDisclosesDeclaredUpstream when trust is given but does not match', function (): void {
    $observation = observationWithChallenge(challengeWithProvenance(
        ProposalProvenance::declared([supportTicketUpstream(trust: Trust::Untrusted)])
    ));

    $result = Assertions::challengeDisclosesDeclaredUpstream(
        'payments.transfer',
        'external:support-ticket-index',
        Trust::Trusted,
    )->evaluate($observation);

    expect($result->passed)->toBeFalse();
});

it('passes challengeDisclosesDeclaredUpstream when trust is omitted regardless of the declared trust', function (): void {
    $observation = observationWithChallenge(challengeWithProvenance(
        ProposalProvenance::declared([supportTicketUpstream(trust: Trust::Trusted)])
    ));

    $result = Assertions::challengeDisclosesDeclaredUpstream(
        'payments.transfer',
        'external:support-ticket-index',
    )->evaluate($observation);

    expect($result->passed)->toBeTrue();
});

it('fails challengeDisclosesDeclaredUpstream when channel is given but does not match', function (): void {
    $observation = observationWithChallenge(challengeWithProvenance(
        ProposalProvenance::declared([supportTicketUpstream(channel: ContextChannel::RetrievedDocument)])
    ));

    $result = Assertions::challengeDisclosesDeclaredUpstream(
        'payments.transfer',
        'external:support-ticket-index',
        null,
        ContextChannel::ToolResult,
    )->evaluate($observation);

    expect($result->passed)->toBeFalse();
});

it('passes challengeDisclosesDeclaredUpstream when channel is omitted regardless of the declared channel', function (): void {
    $observation = observationWithChallenge(challengeWithProvenance(
        ProposalProvenance::declared([supportTicketUpstream(channel: ContextChannel::ToolResult)])
    ));

    $result = Assertions::challengeDisclosesDeclaredUpstream(
        'payments.transfer',
        'external:support-ticket-index',
    )->evaluate($observation);

    expect($result->passed)->toBeTrue();
});

it('throws CapabilityNotAttempted for challengeDisclosesDeclaredUpstream when the capability appears nowhere in the observation', function (): void {
    expect(fn () => Assertions::challengeDisclosesDeclaredUpstream(
        'payments.transfer',
        'external:support-ticket-index',
    )->evaluate(observationWhereCapabilityWasNeverAttempted()))
        ->toThrow(CapabilityNotAttempted::class);
});
