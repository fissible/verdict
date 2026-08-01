<?php

declare(strict_types=1);

namespace Fissible\Verdict;

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Decisions\ExecutionResult;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\LaravelAi\BoundTool;
use Fissible\Verdict\LaravelAi\GuardedTool;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final readonly class VerdictManager
{
    public function __construct(
        private CapabilityRegistry $capabilities,
        private CapabilityAuthorizer $authorizer,
        private EvidenceRecorder $evidence,
        private string $deniedMessage,
    ) {}

    public function capability(Capability $capability): self
    {
        $this->capabilities->register($capability);

        return $this;
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
        $target = $capability->resolveTarget($envelope);

        return $this->record(new Evaluation(
            envelope: $envelope,
            capability: $capability,
            target: $target,
            decision: $this->authorizer->decide($capability, $envelope, $target),
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

    private function record(Evaluation $evaluation): Evaluation
    {
        $this->evidence->record(DecisionEvidence::fromEvaluation($evaluation));

        return $evaluation;
    }
}
