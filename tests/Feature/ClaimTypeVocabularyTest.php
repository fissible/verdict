<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalEvidencePhase;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evidence\ClaimType;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;

/**
 * The vocabulary is a curated judgment, not a formula, so the test that keeps it honest is
 * exhaustiveness over the *discriminating tuple of each stage* — not over `stage`×`disposition`
 * uniformly. Two stages record several distinct events behind one pair:
 *
 *   - `execution_claim` + `permit` is emitted both when a claim is admitted (before the executor
 *     runs) and when it completes. Keying on the pair alone would stamp `claim-completed` on
 *     admission rows — the exact overclaim `claimType` exists to prevent.
 *   - `approval` + `permit` is emitted at three phases: proposal validation, execution validation,
 *     and consumption. A consumption spends a single-use receipt; calling it "validated" would
 *     describe a different claim.
 *
 * A new stage, disposition, phase, or claim status therefore fails CI until someone deliberately
 * maps it or declares it unreachable.
 */
it('maps or explicitly declares unreachable every tuple the evaluation state machine can emit', function (): void {
    $unmapped = [];

    foreach (ClaimType::discriminatingTuples() as $tuple) {
        [$stage, $disposition, $discriminator] = $tuple;

        $mapped = ClaimType::for($stage->value, $disposition->value, $discriminator);
        $unreachable = ClaimType::isUnreachable($stage, $disposition, $discriminator);

        if ($mapped === null && ! $unreachable) {
            $unmapped[] = sprintf('%s + %s + %s', $stage->value, $disposition->value, $discriminator ?? '(none)');
        }

        expect($mapped !== null && $unreachable)->toBeFalse(sprintf(
            'A tuple cannot be both mapped and declared unreachable: %s + %s + %s.',
            $stage->value,
            $disposition->value,
            $discriminator ?? '(none)',
        ));
    }

    expect($unmapped)->toBe([], 'Every tuple must be mapped or declared unreachable. Unmapped: '.implode(', ', $unmapped));
});

/**
 * The regression the first draft of this vocabulary actually contained: `execution_claim` + `permit`
 * was to be labelled `claim-completed`, which would have described an admission row — written before
 * the executor is called — as a completion.
 */
it('never labels an execution-claim admission as a completion', function (): void {
    expect(ClaimType::for('execution_claim', 'permit', ExecutionClaimStatus::Claimed->value))
        ->toBe(ClaimType::ExecutionClaimAdmitted)
        ->and(ClaimType::for('execution_claim', 'permit', ExecutionClaimStatus::Completed->value))
        ->toBe(ClaimType::ExecutionClaimCompleted);

    foreach (ClaimType::discriminatingTuples() as [$stage, $disposition, $discriminator]) {
        if ($discriminator === ExecutionClaimStatus::Completed->value && $disposition === Disposition::Permit) {
            continue;
        }

        expect(ClaimType::for($stage->value, $disposition->value, $discriminator))
            ->not->toBe(ClaimType::ExecutionClaimCompleted, sprintf(
                'Only a permitted, completed claim may carry claim-completed; %s + %s + %s must not.',
                $stage->value,
                $disposition->value,
                $discriminator ?? '(none)',
            ));
    }
});

/**
 * The ceiling, asserted rather than only documented: no label may read as a downstream receipt or a
 * resulting state. `claim-completed` is the strongest execution-adjacent claim Verdict can mint, and
 * it is an admission-side belief.
 */
it('mints no label that claims an execution happened or a resulting state', function (): void {
    foreach (ClaimType::cases() as $case) {
        expect($case->value)->toStartWith('verdict.')
            ->and($case->value)->not->toContain('executed')
            ->and($case->value)->not->toContain('receipt-from')
            ->and($case->value)->not->toContain('state')
            ->and($case->value)->not->toContain('effect');
    }

    expect(ClaimType::ExecutionClaimCompleted->describes())->toContain('admission-side belief');
});

/** The approval stage collapses three materially different events onto one disposition. */
it('distinguishes an approval consumption from a validation', function (): void {
    expect(ClaimType::for('approval', 'permit', ApprovalEvidencePhase::ProposalValidation->value))
        ->toBe(ClaimType::ApprovalProposalValidated)
        ->and(ClaimType::for('approval', 'permit', ApprovalEvidencePhase::ExecutionValidation->value))
        ->toBe(ClaimType::ApprovalExecutionValidated)
        ->and(ClaimType::for('approval', 'permit', ApprovalEvidencePhase::Consumption->value))
        ->toBe(ClaimType::ApprovalReceiptConsumed);
});

/** An unknown stage or disposition must not raise: evidence is a record, never a gate. */
it('returns null rather than raising for a value outside the enums', function (): void {
    expect(ClaimType::for('not-a-stage', 'permit', null))->toBeNull()
        ->and(ClaimType::for('proposal', 'not-a-disposition', null))->toBeNull();
});

/** Folded stages keep the outcome in `disposition`, so every disposition shares one label. */
it('folds an authorization stage onto one label, leaving the outcome in the disposition', function (): void {
    foreach (Disposition::cases() as $disposition) {
        expect(ClaimType::for('proposal', $disposition->value, null))->toBe(ClaimType::AuthorizationDecision)
            ->and(ClaimType::for('execution', $disposition->value, null))->toBe(ClaimType::ExecutionAdmission);
    }
});
