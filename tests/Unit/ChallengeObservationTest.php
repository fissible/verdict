<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
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
