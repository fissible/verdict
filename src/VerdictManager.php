<?php

declare(strict_types=1);

namespace Fissible\Verdict;

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApprovalEvidencePhase;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\PendingContextRelease;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Decisions\ExecutionResult;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Exceptions\ExecutionClaimFinalizationFailed;
use Fissible\Verdict\Exceptions\TargetNotResolvable;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimAdmission;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimManager;
use Fissible\Verdict\LaravelAi\BoundTool;
use Fissible\Verdict\LaravelAi\GuardedTool;
use Fissible\Verdict\RateLimits\RateLimitManager;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final readonly class VerdictManager
{
    public function __construct(
        private CapabilityRegistry $capabilities,
        private CapabilityAuthorizer $authorizer,
        private EvidenceRecorder $evidence,
        private ApprovalManager $approvals,
        private ContextReleaseManager $contextReleases,
        private RateLimitManager $rateLimits,
        private ExecutionClaimManager $executionClaims,
        private string $deniedMessage,
    ) {}

    public function capability(Capability $capability): self
    {
        $this->capabilities->register($capability);

        return $this;
    }

    public function releasePolicy(ReleasePolicy $policy): self
    {
        $this->contextReleases->policy($policy);

        return $this;
    }

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>  $payload
     */
    public function release(array|Arrayable $payload): PendingContextRelease
    {
        return $this->contextReleases->prepare($payload);
    }

    public function evaluate(ActionEnvelope $envelope): Evaluation
    {
        if (! $this->capabilities->has($envelope->proposal->capability)) {
            return $this->record(new Evaluation(
                envelope: $envelope,
                capability: null,
                target: null,
                decision: Decision::deny('Capability is not registered.'),
                stage: EvaluationStage::Proposal,
            ));
        }

        $capability = $this->capabilities->get($envelope->proposal->capability);

        try {
            $target = $capability->resolveTarget($envelope);
        } catch (TargetNotResolvable) {
            return $this->record(new Evaluation(
                envelope: $envelope,
                capability: $capability,
                target: null,
                decision: Decision::deny(TargetNotResolvable::DECISION_REASON),
                stage: EvaluationStage::Proposal,
            ));
        }

        $decision = $this->authorizer->decide($capability, $envelope, $target);

        if ($decision->permitsExecution() && $capability->confirmationRequired()) {
            $decision = Decision::requireConfirmation($capability->confirmationReason());
        }

        return $this->record(new Evaluation(
            envelope: $envelope,
            capability: $capability,
            target: $target,
            decision: $decision,
            stage: EvaluationStage::Proposal,
        ));
    }

    /**
     * @param  callable(Evaluation): mixed  $executor
     */
    public function run(ActionEnvelope $envelope, callable $executor): ExecutionResult
    {
        $evaluation = $this->evaluate($envelope);

        if (! $evaluation->decision->permitsExecution()) {
            return ExecutionResult::denied($evaluation);
        }

        return $this->execute($evaluation, fn (): mixed => $executor($evaluation));
    }

    public function runBound(ActionEnvelope $envelope): ExecutionResult
    {
        $proposalEvaluation = $this->evaluate($envelope);

        if (! in_array($proposalEvaluation->decision->disposition, [
            Disposition::Permit,
            Disposition::RequireConfirmation,
        ], true)) {
            return ExecutionResult::denied($proposalEvaluation);
        }

        $capability = $proposalEvaluation->capability;

        if ($capability === null || ! $capability->isExecutable()) {
            return ExecutionResult::denied($this->record(new Evaluation(
                envelope: $envelope,
                capability: $capability,
                target: $proposalEvaluation->target,
                decision: Decision::deny('Capability does not define a target-bound executor.'),
                stage: EvaluationStage::Execution,
            )));
        }

        $targetPolicy = $capability->executionTargetPolicy();

        if ($targetPolicy === null) {
            return ExecutionResult::denied($this->record(new Evaluation(
                envelope: $envelope,
                capability: $capability,
                target: $proposalEvaluation->target,
                decision: Decision::deny('Capability does not define an execution-target policy.'),
                stage: EvaluationStage::Execution,
            )));
        }

        $confirmationRequired = $proposalEvaluation->decision->disposition === Disposition::RequireConfirmation;

        if ($confirmationRequired) {
            $approvalEvaluation = $this->approvalEvaluation(
                $proposalEvaluation,
                $this->approvals->validate($proposalEvaluation),
                ApprovalEvidencePhase::ProposalValidation,
            );

            if (! $approvalEvaluation->decision->permitsExecution()) {
                return ExecutionResult::denied($approvalEvaluation);
            }
        }

        $refreshEvaluation = $this->refreshTarget($proposalEvaluation, $targetPolicy);

        if (! $refreshEvaluation->decision->permitsExecution()) {
            return ExecutionResult::denied($refreshEvaluation);
        }

        $executionEvaluation = $this->record(new Evaluation(
            envelope: $envelope,
            capability: $capability,
            target: $refreshEvaluation->target,
            decision: $this->authorizer->decide($capability, $envelope, $refreshEvaluation->target),
            stage: EvaluationStage::Execution,
        ));

        if (! $executionEvaluation->decision->permitsExecution()) {
            return ExecutionResult::denied($executionEvaluation);
        }

        if ($confirmationRequired) {
            $approvalEvaluation = $this->approvalEvaluation(
                $executionEvaluation,
                $this->approvals->validate($executionEvaluation),
                ApprovalEvidencePhase::ExecutionValidation,
            );

            if (! $approvalEvaluation->decision->permitsExecution()) {
                return ExecutionResult::denied($approvalEvaluation);
            }
        }

        $rateLimitEvaluation = $this->rateLimit($executionEvaluation);

        if ($rateLimitEvaluation !== null && ! $rateLimitEvaluation->decision->permitsExecution()) {
            return ExecutionResult::denied($rateLimitEvaluation);
        }

        if ($confirmationRequired) {
            $approvalEvaluation = $this->approvalEvaluation(
                $executionEvaluation,
                $this->approvals->consume($executionEvaluation),
                ApprovalEvidencePhase::Consumption,
            );

            if (! $approvalEvaluation->decision->permitsExecution()) {
                return ExecutionResult::denied($approvalEvaluation);
            }
        }

        return $this->executeAfterRateLimit(
            $executionEvaluation,
            fn (): mixed => $capability->execute(AuthorizedAction::fromExecutionEvaluation($executionEvaluation)),
        );
    }

    /**
     * @param  ActionContext|callable(Request): ActionContext  $context
     */
    public function guard(Tool $tool, string $capability, ActionContext|callable $context): GuardedTool
    {
        return new GuardedTool($tool, $capability, $context, $this, $this->deniedMessage);
    }

    /**
     * @param  ActionContext|callable(Request): ActionContext  $context
     */
    public function bound(Tool $definition, string $capability, ActionContext|callable $context): BoundTool
    {
        return new BoundTool($definition, $capability, $context, $this, $this->deniedMessage);
    }

    public function requestConfirmation(ActionEnvelope $envelope): ?Decision
    {
        if (! $this->capabilities->has($envelope->proposal->capability)) {
            return null;
        }

        $capability = $this->capabilities->get($envelope->proposal->capability);

        if (! $capability->confirmationRequired()
            || ! $capability->isExecutable()
            || $capability->executionTargetPolicy() === null) {
            return null;
        }

        $evaluation = $this->evaluate($envelope);

        if ($evaluation->decision->disposition !== Disposition::RequireConfirmation) {
            return null;
        }

        $this->approvals->issue($evaluation);

        return $evaluation->decision;
    }

    public function approvals(): ApprovalManager
    {
        return $this->approvals;
    }

    public function executionClaims(): ExecutionClaimManager
    {
        return $this->executionClaims;
    }

    private function record(Evaluation $evaluation): Evaluation
    {
        $this->evidence->record(DecisionEvidence::fromEvaluation($evaluation));

        return $evaluation;
    }

    private function rateLimit(Evaluation $evaluation): ?Evaluation
    {
        $capability = $evaluation->capability;

        if ($capability === null || $capability->rateLimitPolicy() === null) {
            return null;
        }

        return $this->record(new Evaluation(
            envelope: $evaluation->envelope,
            capability: $capability,
            target: $evaluation->target,
            decision: $this->rateLimits->consume($capability, $evaluation->envelope, $evaluation->target),
            stage: EvaluationStage::RateLimit,
        ));
    }

    /** @param callable(): mixed $executor */
    private function execute(Evaluation $evaluation, callable $executor): ExecutionResult
    {
        $rateLimitEvaluation = $this->rateLimit($evaluation);

        if ($rateLimitEvaluation !== null && ! $rateLimitEvaluation->decision->permitsExecution()) {
            return ExecutionResult::denied($rateLimitEvaluation);
        }

        return $this->executeAfterRateLimit($evaluation, $executor);
    }

    /** @param callable(): mixed $executor */
    private function executeAfterRateLimit(Evaluation $evaluation, callable $executor): ExecutionResult
    {
        $admission = $this->claimExecution($evaluation);

        if ($admission !== null && ! $admission->admitted()) {
            return ExecutionResult::denied(new Evaluation(
                envelope: $evaluation->envelope,
                capability: $evaluation->capability,
                target: $evaluation->target,
                decision: $admission->decision,
                stage: EvaluationStage::ExecutionClaim,
            ));
        }

        try {
            $output = $executor();
        } catch (Throwable $executionFailure) {
            if ($admission !== null) {
                try {
                    $this->recordClaimDecision(
                        $evaluation,
                        $this->executionClaims->markIndeterminate($admission),
                    );
                } catch (Throwable $finalizationFailure) {
                    throw ExecutionClaimFinalizationFailed::fromFailures(
                        $executionFailure,
                        $finalizationFailure,
                    );
                }
            }

            throw $executionFailure;
        }

        if ($admission !== null) {
            $this->recordClaimDecision($evaluation, $this->executionClaims->complete($admission));
        }

        return ExecutionResult::executed($evaluation, $output);
    }

    private function approvalEvaluation(
        Evaluation $evaluation,
        ApprovalTransition $transition,
        ApprovalEvidencePhase $phase,
    ): Evaluation {
        $succeeded = match ($phase) {
            ApprovalEvidencePhase::ProposalValidation,
            ApprovalEvidencePhase::ExecutionValidation => $transition->outcome === ApprovalOutcome::Approved,
            ApprovalEvidencePhase::Consumption => $transition->outcome === ApprovalOutcome::Consumed,
        };
        $metadata = [
            'approval_phase' => $phase->value,
            'approval_outcome' => $transition->outcome->value,
            'approval_receipt_fingerprint' => $transition->receipt === null
                ? null
                : hash('sha256', $transition->receipt->id),
        ];

        return $this->record(new Evaluation(
            envelope: $evaluation->envelope,
            capability: $evaluation->capability,
            target: $evaluation->target,
            decision: $succeeded
                ? Decision::permit(
                    $phase === ApprovalEvidencePhase::Consumption
                        ? 'An approved action receipt was consumed.'
                        : 'An approved action receipt was validated.',
                    $metadata,
                )
                : Decision::requireConfirmation(
                    "Approval receipt {$phase->value} failed with outcome {$transition->outcome->value}.",
                    $metadata,
                ),
            stage: EvaluationStage::Approval,
        ));
    }

    private function refreshTarget(
        Evaluation $proposalEvaluation,
        ExecutionTargetPolicy $policy,
    ): Evaluation {
        $capability = $proposalEvaluation->capability;

        if ($capability === null) {
            throw new \LogicException('Target refresh requires a resolved capability.');
        }

        $proposalFingerprint = $this->targetIdentityFingerprint(
            $capability,
            $policy,
            $proposalEvaluation,
            $proposalEvaluation->target,
        );
        $baseMetadata = [
            'target_policy' => $policy->name,
            'target_strategy' => $policy->strategy->value,
            'proposal_target_identity_fingerprint' => $proposalFingerprint,
        ];

        try {
            $executionTarget = $policy->targetForExecution(
                $proposalEvaluation->envelope,
                $proposalEvaluation->target,
            );
        } catch (TargetNotResolvable) {
            return $this->record(new Evaluation(
                envelope: $proposalEvaluation->envelope,
                capability: $capability,
                target: null,
                decision: Decision::deny(TargetNotResolvable::DECISION_REASON, [
                    ...$baseMetadata,
                    'execution_target_identity_fingerprint' => null,
                    'target_identity_matched' => null,
                ]),
                stage: EvaluationStage::TargetRefresh,
            ));
        }

        $executionFingerprint = $this->targetIdentityFingerprint(
            $capability,
            $policy,
            $proposalEvaluation,
            $executionTarget,
        );
        $matches = hash_equals($proposalFingerprint, $executionFingerprint);

        return $this->record(new Evaluation(
            envelope: $proposalEvaluation->envelope,
            capability: $capability,
            target: $executionTarget,
            decision: $matches
                ? Decision::permit('Execution target identity matched the proposal target.', [
                    ...$baseMetadata,
                    'execution_target_identity_fingerprint' => $executionFingerprint,
                    'target_identity_matched' => true,
                ])
                : Decision::deny('Execution target identity did not match the proposal target.', [
                    ...$baseMetadata,
                    'execution_target_identity_fingerprint' => $executionFingerprint,
                    'target_identity_matched' => false,
                ]),
            stage: EvaluationStage::TargetRefresh,
        ));
    }

    private function targetIdentityFingerprint(
        Capability $capability,
        ExecutionTargetPolicy $policy,
        Evaluation $evaluation,
        mixed $target,
    ): string {
        return ArgumentFingerprint::make([
            'capability' => $capability->name,
            'target_policy' => $policy->name,
            'identity' => $policy->identity($evaluation->envelope, $target),
        ]);
    }

    private function claimExecution(Evaluation $evaluation): ?ExecutionClaimAdmission
    {
        $capability = $evaluation->capability;

        if ($capability === null || $capability->executionClaimPolicy() === null) {
            return null;
        }

        $admission = $this->executionClaims->claim(
            $capability,
            $evaluation->envelope,
            $evaluation->target,
        );
        $this->recordClaimDecision($evaluation, $admission->decision);

        return $admission;
    }

    private function recordClaimDecision(Evaluation $evaluation, Decision $decision): Evaluation
    {
        return $this->record(new Evaluation(
            envelope: $evaluation->envelope,
            capability: $evaluation->capability,
            target: $evaluation->target,
            decision: $decision,
            stage: EvaluationStage::ExecutionClaim,
        ));
    }
}
