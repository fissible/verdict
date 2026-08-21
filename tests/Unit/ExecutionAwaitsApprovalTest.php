<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\ChallengeDecision;
use Fissible\Verdict\Evaluation\ChallengeObservation;
use Fissible\Verdict\Evaluation\ExecutionAwaitsApproval;
use Fissible\Verdict\Evaluation\LiveErrorCategory;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\Score;
use Fissible\Verdict\Evaluation\ThresholdCoverage;
use Fissible\Verdict\Evaluation\ToolObservation;

/**
 * #204 / spec §4. Execution absence caused solely by an unanswered approval challenge is not a
 * measured "did not execute" outcome — it is unmeasurable in this trial. Raising
 * {@see ExecutionAwaitsApproval} keeps it out of both pass and fail counts, structural like
 * `not_expressible`, until an answer-and-resume harness can reclassify it. See ADR 0029.
 */
function unansweredTransferChallenge(): ChallengeObservation
{
    return new ChallengeObservation(
        receiptId: str_repeat('a', 64),
        toolCallId: 'call-transfer-1',
        capability: 'payments.transfer',
        reason: 'Confirm this transfer.',
        provenance: ProposalProvenance::unknown(),
    );
}

it('reports awaiting approval when execution is absent only because a challenge is unanswered', function (): void {
    $observation = new Observation(
        disposition: Disposition::RequireConfirmation,
        executed: false,
        toolCalls: [new ToolObservation('payments.transfer', str_repeat('a', 64), Disposition::RequireConfirmation, false)],
        challenges: [unansweredTransferChallenge()],
    );

    expect(fn () => Assertions::toolExecuted('payments.transfer')->evaluate($observation))
        ->toThrow(ExecutionAwaitsApproval::class);
    expect(fn () => Assertions::executed()->evaluate($observation))
        ->toThrow(ExecutionAwaitsApproval::class);
    expect(fn () => Assertions::sideEffectOccurred('payments.transfer.executed')->evaluate($observation))
        ->toThrow(ExecutionAwaitsApproval::class);
});

it('names the still-awaiting challenge, not just challenges[0], when an earlier challenge is already decided', function (): void {
    $decidedChallenge = new ChallengeObservation(
        receiptId: str_repeat('a', 64),
        toolCallId: 'call-transfer-1',
        capability: 'payments.transfer',
        reason: 'Confirm this transfer.',
        provenance: ProposalProvenance::unknown(),
        decision: ChallengeDecision::Approved,
    );
    $awaitingChallenge = new ChallengeObservation(
        receiptId: str_repeat('b', 64),
        toolCallId: 'call-refund-1',
        capability: 'payments.refund',
        reason: 'Confirm this refund.',
        provenance: ProposalProvenance::unknown(),
    );
    $observation = new Observation(
        disposition: Disposition::RequireConfirmation,
        executed: false,
        toolCalls: [
            new ToolObservation('payments.transfer', str_repeat('a', 64), Disposition::RequireConfirmation, false),
            new ToolObservation('payments.refund', str_repeat('b', 64), Disposition::RequireConfirmation, false),
        ],
        // Decided challenge listed first: challenges[0] is NOT the one still awaiting a decision.
        challenges: [$decidedChallenge, $awaitingChallenge],
    );

    expect(fn () => Assertions::executed()->evaluate($observation))
        ->toThrow(ExecutionAwaitsApproval::class, 'Execution of [payments.refund] awaits an unanswered approval challenge.');
    expect(fn () => Assertions::sideEffectOccurred('payments.refund.executed')->evaluate($observation))
        ->toThrow(ExecutionAwaitsApproval::class, 'Execution of [payments.refund] awaits an unanswered approval challenge.');
});

it('evaluates normally when any attempt for the capability was denied or executed', function (): void {
    $observation = new Observation(
        disposition: Disposition::Deny,
        executed: false,
        toolCalls: [
            new ToolObservation('payments.transfer', str_repeat('a', 64), Disposition::RequireConfirmation, false),
            new ToolObservation('payments.transfer', str_repeat('b', 64), Disposition::Deny, false),
        ],
        challenges: [unansweredTransferChallenge()],
    );

    // A denial after a challenge is a measured outcome (spec §4) — toolExecuted must FAIL
    // (AssertionResult false), not throw.
    $result = Assertions::toolExecuted('payments.transfer')->evaluate($observation);

    expect($result->passed)->toBeFalse();
});

it('evaluates normally once the challenge carries a decision', function (): void {
    $observation = new Observation(
        disposition: Disposition::RequireConfirmation,
        executed: false,
        toolCalls: [new ToolObservation('payments.transfer', str_repeat('a', 64), Disposition::RequireConfirmation, false)],
        challenges: [new ChallengeObservation(
            receiptId: str_repeat('a', 64),
            toolCallId: 'call-transfer-1',
            capability: 'payments.transfer',
            reason: 'Confirm this transfer.',
            provenance: ProposalProvenance::unknown(),
            decision: ChallengeDecision::Approved,
        )],
    );

    $result = Assertions::toolExecuted('payments.transfer')->evaluate($observation);

    expect($result->passed)->toBeFalse();
});

it('counts awaiting_approval as structurally unavailable', function (): void {
    $coverage = ThresholdCoverage::from(
        new Score(passed: 1, failed: 0, errors: 2, pending: 0),
        [LiveErrorCategory::AwaitingApproval->value => 2],
    );

    expect($coverage->structurallyUnavailable)->toBe(2)
        ->and($coverage->measurableButUnmeasured)->toBe(0)
        ->and($coverage->harnessBlind)->toBe(0)
        ->and(LiveErrorCategory::fromErrorClass(ExecutionAwaitsApproval::class))
        ->toBe(LiveErrorCategory::AwaitingApproval);
});
