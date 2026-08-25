<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Approvals\ApprovalEvidencePhase;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;

/**
 * What a decision-evidence record asserts, as one stable, namespaced label.
 *
 * This is the record's *semantic* identity, the counterpart to `recordDigest`'s cryptographic one.
 * An external reader citing a Verdict record learns what it substantiates without reading Verdict's
 * documentation, and — more importantly — cannot mistake an authorization decision for an execution
 * or a resulting state.
 *
 * **The ceiling.** No label in this vocabulary claims an operation happened, that a downstream
 * system committed, or what the resulting state was. Verdict does not observe any of those. The
 * strongest execution-adjacent label is {@see self::ExecutionClaimCompleted}, and it records
 * Verdict marking *its own* claim complete around a successful return — an admission-side belief,
 * never a receipt from the executor, and it carries no result.
 *
 * **This is a public, versioned, additive-only vocabulary.** The strings are decoupled from the
 * internal `stage`/`disposition` names on purpose: an internal rename must not silently break an
 * external reference. Add cases; do not repurpose or remove them.
 *
 * **The map is a judgment, keyed per stage.** It is deliberately not a mechanical
 * `verdict.<stage>.<disposition>`, which would both leak internal names into the contract and mint
 * `verdict.execution.permit` — a string that reads as "execution happened." It is also not keyed on
 * `stage`+`disposition` uniformly, because two stages emit several distinct events behind one pair
 * (see {@see self::discriminatorFor()}). Some outcomes fold onto one label with the outcome left in
 * `disposition`; others earn their own. `ClaimTypeVocabularyTest` fails until every tuple the
 * evaluation state machine can emit is either mapped here or declared unreachable.
 */
enum ClaimType: string
{
    /**
     * An authorizer decided about a proposed action. The outcome — permitted, denied, gated on a
     * human — lives in `disposition`; every proposal-stage outcome is the same kind of claim.
     */
    case AuthorizationDecision = 'verdict.authorization.decision';

    /** An action was admitted to, or refused admission to, its executor. Not that it ran. */
    case ExecutionAdmission = 'verdict.execution.admission';

    /** An approval receipt satisfied validation at the proposal gate. */
    case ApprovalProposalValidated = 'verdict.approval.proposal-validated';

    /** An approval receipt satisfied re-validation at the execution gate. */
    case ApprovalExecutionValidated = 'verdict.approval.execution-validated';

    /** A single-use approval receipt was spent, and cannot authorize a further action. */
    case ApprovalReceiptConsumed = 'verdict.approval.receipt-consumed';

    /** Proposal-gate validation did not accept the receipt, so a human confirmation is required. */
    case ApprovalProposalValidationFailed = 'verdict.approval.proposal-validation-failed';

    /** Execution-gate re-validation did not accept the receipt. */
    case ApprovalExecutionValidationFailed = 'verdict.approval.execution-validation-failed';

    /** A receipt could not be spent — the signal a replay of an already-consumed receipt produces. */
    case ApprovalConsumptionFailed = 'verdict.approval.consumption-failed';

    /** The execution-time target identity was compared against the proposal's. `disposition` carries whether they matched. */
    case TargetRefresh = 'verdict.target.refresh';

    /** A semantic rate-limit budget was consumed by this attempt. */
    case RateLimitConsumption = 'verdict.rate-limit.consumption';

    /** A semantic rate limit refused this attempt. */
    case RateLimitRefusal = 'verdict.rate-limit.refusal';

    /**
     * A write-ahead intent record was committed before any security state was consumed (#160).
     * This is the mirror of an operational row, not the row itself; it claims the intent exists,
     * not that anything ran.
     */
    case IntentRecorded = 'verdict.intent.recorded';

    /** The pre-mutation intent write failed, so the action was refused with nothing consumed. */
    case IntentRefused = 'verdict.intent.refused';

    /** An at-most-once claim was admitted: the action was handed to its executor. Nothing has run yet. */
    case ExecutionClaimAdmitted = 'verdict.execution.claim-admitted';

    /**
     * An at-most-once claim was marked complete around a successful executor return.
     *
     * The strongest execution-adjacent claim Verdict can mint, and still an admission-side belief:
     * Verdict completing its own claim, not a receipt from the executor, carrying no result.
     */
    case ExecutionClaimCompleted = 'verdict.execution.claim-completed';

    /** An at-most-once claim refused a duplicate logical action. */
    case ExecutionClaimRefused = 'verdict.execution.claim-refused';

    /**
     * An at-most-once claim is unresolved and needs reconciliation — either this attempt's executor
     * threw after admission, or a duplicate was refused because an earlier attempt is unresolved.
     * The record cannot distinguish the two: it carries the claim's status, not the transition
     * outcome that produced it, and this label does not pretend otherwise.
     */
    case ExecutionClaimIndeterminate = 'verdict.execution.claim-indeterminate';

    /**
     * The label for a recorded evaluation, or null when the tuple is one the state machine does not
     * produce (or the values are outside the enums — evidence is a record, never a gate, so an
     * unrecognized value yields null rather than raising).
     *
     * `$discriminator` is the stage's third key: `approval_phase` for the approval stage,
     * `execution_claim_status` for the execution-claim stage, and ignored elsewhere.
     */
    public static function for(string $stage, string $disposition, ?string $discriminator): ?self
    {
        $resolvedStage = EvaluationStage::tryFrom($stage);
        $resolvedDisposition = Disposition::tryFrom($disposition);

        if ($resolvedStage === null || $resolvedDisposition === null) {
            return null;
        }

        return match ($resolvedStage) {
            EvaluationStage::Proposal => self::AuthorizationDecision,
            EvaluationStage::Execution => self::ExecutionAdmission,
            EvaluationStage::Intent => match ($resolvedDisposition) {
                Disposition::Permit => self::IntentRecorded,
                Disposition::Deny => self::IntentRefused,
                default => null,
            },
            EvaluationStage::TargetRefresh => match ($resolvedDisposition) {
                Disposition::Permit, Disposition::Deny => self::TargetRefresh,
                default => null,
            },
            EvaluationStage::RateLimit => match ($resolvedDisposition) {
                Disposition::Permit => self::RateLimitConsumption,
                Disposition::Throttle => self::RateLimitRefusal,
                default => null,
            },
            EvaluationStage::Approval => self::approval($resolvedDisposition, $discriminator),
            EvaluationStage::ExecutionClaim => self::executionClaim($resolvedDisposition, $discriminator),
        };
    }

    /**
     * Which field, beyond `stage` and `disposition`, distinguishes this stage's distinct events —
     * or null where the pair alone is enough.
     *
     * Two stages need one, and both were found by the exhaustiveness requirement rather than by
     * reading the enums:
     *
     * - **`approval`** emits at three phases. `permit` means "a receipt validated at the proposal
     *   gate", "…at the execution gate", *and* "a single-use receipt was spent" — three different
     *   claims, and a consumption failure is a replay signal the other two are not.
     * - **`execution_claim`** emits `permit` both when a claim is admitted, before the executor is
     *   called, and when it completes afterwards. Keying on the pair alone would label admissions
     *   as completions.
     */
    public static function discriminatorFor(EvaluationStage $stage): ?string
    {
        return match ($stage) {
            EvaluationStage::Approval => 'approval_phase',
            EvaluationStage::ExecutionClaim => 'execution_claim_status',
            default => null,
        };
    }

    /**
     * Every tuple the evaluation state machine could present, for the exhaustiveness test to walk.
     *
     * @return list<array{EvaluationStage, Disposition, ?string}>
     */
    public static function discriminatingTuples(): array
    {
        $tuples = [];

        foreach (EvaluationStage::cases() as $stage) {
            foreach (Disposition::cases() as $disposition) {
                foreach (self::discriminatorValues($stage) as $discriminator) {
                    $tuples[] = [$stage, $disposition, $discriminator];
                }
            }
        }

        return $tuples;
    }

    /**
     * Whether the evaluation state machine cannot produce this tuple at all.
     *
     * Declared from a walk of the code that emits each stage, not from what the enums permit: the
     * `proposal` and `execution` stages record an application-supplied `CapabilityAuthorizer`
     * decision, so every disposition is reachable there, while `approval`, `target_refresh`,
     * `rate_limit`, and `execution_claim` decisions are minted by Verdict's own managers and are
     * bounded by what those emit.
     */
    public static function isUnreachable(EvaluationStage $stage, Disposition $disposition, ?string $discriminator): bool
    {
        return match ($stage) {
            // An application-supplied authorizer may return any disposition.
            EvaluationStage::Proposal, EvaluationStage::Execution => false,

            // VerdictManager::refreshTarget() mints only permit (identity matched) or deny.
            EvaluationStage::TargetRefresh => ! in_array($disposition, [Disposition::Permit, Disposition::Deny], true),

            // ActionIntentAdmission mints only permit (recorded) or deny (refused).
            EvaluationStage::Intent => ! in_array($disposition, [Disposition::Permit, Disposition::Deny], true),

            // RateLimitManager::consume() mints only permit or throttle.
            EvaluationStage::RateLimit => ! in_array($disposition, [Disposition::Permit, Disposition::Throttle], true),

            // VerdictManager::recordApprovalEvidence() mints permit on success and
            // requireConfirmation on failure, always with a phase.
            EvaluationStage::Approval => $discriminator === null
                || ! in_array($disposition, [Disposition::Permit, Disposition::RequireConfirmation], true),

            EvaluationStage::ExecutionClaim => self::executionClaimUnreachable($disposition, $discriminator),
        };
    }

    /** A one-line statement of what this label asserts, and where its ceiling is. */
    public function describes(): string
    {
        return match ($this) {
            self::AuthorizationDecision => 'An authorizer decided about a proposed action; the outcome is in the disposition.',
            self::ExecutionAdmission => 'An action was admitted to, or refused admission to, its executor — not that it ran.',
            self::ApprovalProposalValidated => 'An approval receipt satisfied validation at the proposal gate.',
            self::ApprovalExecutionValidated => 'An approval receipt satisfied re-validation at the execution gate.',
            self::ApprovalReceiptConsumed => 'A single-use approval receipt was spent and can authorize nothing further.',
            self::ApprovalProposalValidationFailed => 'Proposal-gate validation did not accept the receipt; a human confirmation is required.',
            self::ApprovalExecutionValidationFailed => 'Execution-gate re-validation did not accept the receipt.',
            self::ApprovalConsumptionFailed => 'A receipt could not be spent — the signal a replay of a consumed receipt produces.',
            self::TargetRefresh => 'The execution-time target identity was compared against the proposal target.',
            self::RateLimitConsumption => 'A semantic rate-limit budget was consumed by this attempt.',
            self::RateLimitRefusal => 'A semantic rate limit refused this attempt.',
            self::IntentRecorded => 'A write-ahead intent record was committed before any security state was consumed; nothing has run.',
            self::IntentRefused => 'The pre-mutation intent write failed, so the action was refused with nothing consumed.',
            self::ExecutionClaimAdmitted => 'An at-most-once claim was admitted and the action handed to its executor; nothing has run.',
            self::ExecutionClaimCompleted => 'Verdict marked its own at-most-once claim complete around a successful return: an admission-side belief, never a receipt from the executor, carrying no result.',
            self::ExecutionClaimRefused => 'An at-most-once claim refused a duplicate logical action.',
            self::ExecutionClaimIndeterminate => 'An at-most-once claim is unresolved and needs reconciliation.',
        };
    }

    /** @return list<?string> */
    private static function discriminatorValues(EvaluationStage $stage): array
    {
        return match ($stage) {
            EvaluationStage::Approval => [
                ...array_map(static fn (ApprovalEvidencePhase $p): string => $p->value, ApprovalEvidencePhase::cases()),
                null,
            ],
            EvaluationStage::ExecutionClaim => [
                ...array_map(static fn (ExecutionClaimStatus $s): string => $s->value, ExecutionClaimStatus::cases()),
                null,
            ],
            default => [null],
        };
    }

    private static function approval(Disposition $disposition, ?string $phase): ?self
    {
        $resolved = $phase === null ? null : ApprovalEvidencePhase::tryFrom($phase);

        if ($resolved === null) {
            return null;
        }

        return match ($disposition) {
            Disposition::Permit => match ($resolved) {
                ApprovalEvidencePhase::ProposalValidation => self::ApprovalProposalValidated,
                ApprovalEvidencePhase::ExecutionValidation => self::ApprovalExecutionValidated,
                ApprovalEvidencePhase::Consumption => self::ApprovalReceiptConsumed,
            },
            Disposition::RequireConfirmation => match ($resolved) {
                ApprovalEvidencePhase::ProposalValidation => self::ApprovalProposalValidationFailed,
                ApprovalEvidencePhase::ExecutionValidation => self::ApprovalExecutionValidationFailed,
                ApprovalEvidencePhase::Consumption => self::ApprovalConsumptionFailed,
            },
            default => null,
        };
    }

    private static function executionClaim(Disposition $disposition, ?string $status): ?self
    {
        $resolved = $status === null ? null : ExecutionClaimStatus::tryFrom($status);

        return match ($disposition) {
            // ExecutionClaimManager::admit() permits only a fresh claim; complete() only a completed one.
            Disposition::Permit => match ($resolved) {
                ExecutionClaimStatus::Claimed => self::ExecutionClaimAdmitted,
                ExecutionClaimStatus::Completed => self::ExecutionClaimCompleted,
                default => null,
            },
            // A refused duplicate carries the *existing* claim's status, which may be any of them —
            // or none, when the store returned no claim at all. Indeterminate is called out because
            // it is the state that needs an operator, however it was reached.
            Disposition::Deny => $resolved === ExecutionClaimStatus::Indeterminate
                ? self::ExecutionClaimIndeterminate
                : self::ExecutionClaimRefused,
            default => null,
        };
    }

    private static function executionClaimUnreachable(Disposition $disposition, ?string $status): bool
    {
        if (! in_array($disposition, [Disposition::Permit, Disposition::Deny], true)) {
            return true;
        }

        if ($disposition === Disposition::Deny) {
            return false;
        }

        return ! in_array($status, [ExecutionClaimStatus::Claimed->value, ExecutionClaimStatus::Completed->value], true);
    }
}
