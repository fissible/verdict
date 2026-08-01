<?php

declare(strict_types=1);

namespace Fissible\Verdict;

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApprovalManager;
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
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Exceptions\TargetNotResolvable;
use Fissible\Verdict\LaravelAi\BoundTool;
use Fissible\Verdict\LaravelAi\GuardedTool;
use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final readonly class VerdictManager
{
    public function __construct(
        private CapabilityRegistry $capabilities,
        private CapabilityAuthorizer $authorizer,
        private EvidenceRecorder $evidence,
        private ApprovalManager $approvals,
        private ContextReleaseManager $contextReleases,
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

        return ExecutionResult::executed($evaluation, $executor($evaluation));
    }

    public function runBound(ActionEnvelope $envelope): ExecutionResult
    {
        $proposalEvaluation = $this->evaluate($envelope);

        if ($proposalEvaluation->decision->disposition === Disposition::RequireConfirmation) {
            $receipt = $this->approvals->consume($proposalEvaluation);

            if (! $receipt->succeeded()) {
                return ExecutionResult::denied($proposalEvaluation);
            }

            $proposalEvaluation = $this->record(new Evaluation(
                envelope: $envelope,
                capability: $proposalEvaluation->capability,
                target: $proposalEvaluation->target,
                decision: Decision::permit(
                    reason: 'An approved action receipt was consumed.',
                    metadata: [
                        'approval_receipt_fingerprint' => $receipt->receipt === null
                            ? null
                            : hash('sha256', $receipt->receipt->id),
                    ],
                ),
                stage: EvaluationStage::Approval,
            ));
        }

        if (! $proposalEvaluation->decision->permitsExecution()) {
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

        $executionEvaluation = $this->record(new Evaluation(
            envelope: $envelope,
            capability: $capability,
            target: $proposalEvaluation->target,
            decision: $this->authorizer->decide($capability, $envelope, $proposalEvaluation->target),
            stage: EvaluationStage::Execution,
        ));

        if (! $executionEvaluation->decision->permitsExecution()) {
            return ExecutionResult::denied($executionEvaluation);
        }

        return ExecutionResult::executed(
            $executionEvaluation,
            $capability->execute(AuthorizedAction::fromExecutionEvaluation($executionEvaluation)),
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

        if (! $capability->confirmationRequired()) {
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

    private function record(Evaluation $evaluation): Evaluation
    {
        $this->evidence->record(DecisionEvidence::fromEvaluation($evaluation));

        return $evaluation;
    }
}
