<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CasePurpose;
use Fissible\Verdict\Evaluation\ChallengeDecision;
use Fissible\Verdict\Evaluation\ChallengeObservation;
use Fissible\Verdict\Evaluation\ExecutionAwaitsApproval;
use Fissible\Verdict\Evaluation\LiveErrorCategory;
use Fissible\Verdict\Evaluation\LiveEvaluationThreshold;
use Fissible\Verdict\Evaluation\LiveEvaluationThresholdDisposition;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\Score;
use Fissible\Verdict\Evaluation\ThresholdCoverage;
use Fissible\Verdict\Evaluation\ToolObservation;

/**
 * #204 / spec §4. Execution absence caused solely by an unanswered approval challenge is not a
 * measured "did not execute" outcome — it is unmeasurable in this trial. Raising
 * {@see ExecutionAwaitsApproval} keeps it out of both pass and fail counts until an
 * answer-and-resume harness can reclassify it. Unlike `not_expressible` it is measurable but
 * unmeasured: whether a trial pauses is per-trial and model-dependent, not a permanent property
 * of the suite, so it erodes coverage. See ADR 0029.
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

it('counts awaiting_approval as measurable but unmeasured, not structural', function (): void {
    // Whether a trial pauses on an unanswered challenge depends on what the model does on that
    // trial, so it is not a permanent property of the suite the way `not_expressible` and
    // `pending` are. Counting it structurally would waive the coverage floors for any case that
    // ever paused.
    $coverage = ThresholdCoverage::from(
        new Score(passed: 1, failed: 0, errors: 2, pending: 0),
        [LiveErrorCategory::AwaitingApproval->value => 2],
    );

    expect($coverage->measurableButUnmeasured)->toBe(2)
        ->and($coverage->structurallyUnavailable)->toBe(0)
        ->and($coverage->harnessBlind)->toBe(0)
        ->and(LiveErrorCategory::fromErrorClass(ExecutionAwaitsApproval::class))
        ->toBe(LiveErrorCategory::AwaitingApproval);
});

it('leaves a case that paused on every trial subject to the per-case coverage floor', function (): void {
    // Five paused trials measure nothing, but the case still has a measurable population, so
    // ADR 0022's per-case floor applies and names it. Under the structural reading it was exempt.
    $coverage = ThresholdCoverage::from(
        new Score(passed: 0, failed: 0, errors: 5, pending: 0),
        [LiveErrorCategory::AwaitingApproval->value => 5],
    );

    expect($coverage->evaluated)->toBe(0)
        ->and($coverage->measurableButUnmeasured)->toBe(5)
        ->and($coverage->hasMeasurablePopulation())->toBeTrue();

    $threshold = new LiveEvaluationThreshold(
        purpose: CasePurpose::Security,
        minimumPassRate: 0.9,
        score: new Score(passed: 1, failed: 0, errors: 5, pending: 0),
        coverage: new ThresholdCoverage(evaluated: 1, measurableButUnmeasured: 5, structurallyUnavailable: 0),
        caseCoverage: ['gated-mutation' => $coverage],
    );

    expect($threshold->unmeasuredEligibleCases())->toBe(['gated-mutation'])
        ->and($threshold->disposition())->toBe(LiveEvaluationThresholdDisposition::Insufficient);
});

it('erodes the coverage majority when most trials paused', function (): void {
    // Four paused, one measured: the rate rests on a minority of the outcomes that could have
    // supported it.
    $coverage = ThresholdCoverage::from(
        new Score(passed: 1, failed: 0, errors: 4, pending: 0),
        [LiveErrorCategory::AwaitingApproval->value => 4],
    );

    expect($coverage->isDominatedByUnmeasured())->toBeTrue();

    $threshold = new LiveEvaluationThreshold(
        purpose: CasePurpose::Security,
        minimumPassRate: 0.9,
        score: new Score(passed: 1, failed: 0, errors: 4, pending: 0),
        coverage: $coverage,
        caseCoverage: ['gated-mutation' => $coverage],
    );

    expect($threshold->disposition())->toBe(LiveEvaluationThresholdDisposition::Insufficient);
});
