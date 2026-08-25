<?php

declare(strict_types=1);

namespace Fissible\Verdict;

use Closure;
use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApprovalEvidencePhase;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Approvals\ProposalAnchor;
use Fissible\Verdict\Approvals\StrictProvenanceGuard;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\PendingContextRelease;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\ExecutionWindow;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Decisions\ExecutionResult;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\Verdict\Evidence\NullRecorderWarning;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\Exceptions\CapabilityNotExecutable;
use Fissible\Verdict\Exceptions\ExecutionClaimFinalizationFailed;
use Fissible\Verdict\Exceptions\ExecutionCompletedWithUnfinalizedClaim;
use Fissible\Verdict\Exceptions\TargetNotResolvable;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimAdmission;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimManager;
use Fissible\Verdict\Intents\ActionIntentManager;
use Fissible\Verdict\Intents\Events\ActionIntentWriteFailed;
use Fissible\Verdict\LaravelAi\BoundTool;
use Fissible\Verdict\LaravelAi\GuardedTool;
use Fissible\Verdict\LaravelAi\InvocationContext;
use Fissible\Verdict\RateLimits\RateLimitManager;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final readonly class VerdictManager
{
    /**
     * @internal Resolve VerdictManager from the container. This constructor is not part of the
     *           supported surface and may gain required parameters in any release.
     *           See docs/adr/0019-verdict-services-are-container-resolved.md.
     */
    public function __construct(
        private CapabilityRegistry $capabilities,
        private CapabilityAuthorizer $authorizer,
        private EvidenceWriter $evidence,
        private ApprovalManager $approvals,
        private ApprovalExecutionContext $approvalExecutions,
        private ContextReleaseManager $contextReleases,
        private RateLimitManager $rateLimits,
        private ExecutionClaimManager $executionClaims,
        private ProvenanceLedger $provenance,
        private InvocationContext $invocations,
        private ActionIntentManager $intents,
        private StrictProvenanceGuard $strictProvenance,
        private string $deniedMessage,
        private Dispatcher $events,
        private NullRecorderWarning $nullRecorderWarning,
        /**
         * Resolved per execution, never at construction: a provider that type-hints this manager
         * in boot() constructs it before any evaluation harness runs, and an eagerly-captured
         * window would freeze as null — every filtered-permit trial silently unmeasured.
         *
         * @var (Closure(): ?ExecutionWindow)|null
         */
        private ?Closure $executionWindow = null,
    ) {}

    public function capability(Capability $capability): self
    {
        $this->capabilities->register($capability);

        return $this;
    }

    public function registeredCapability(string $name): Capability
    {
        return $this->capabilities->get($name);
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

        // A capability requiring confirmation or at-most-once execution is exactly where an
        // unrecorded decision is most consequential — an approval nobody can later prove was
        // granted, a claim whose admission history is unrecoverable. Warn once per process if such
        // a capability is running under the shipped no-op recorder. Advisory only (ADR 0007). #194.
        if ($capability->isConsequential()) {
            $this->nullRecorderWarning->noteConsequentialAction($this->evidence, $this->events);
        }

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
            $decision = $this->strictProvenanceDenial($capability, $envelope)
                ?? Decision::requireConfirmation($capability->confirmationReason());
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
     * Under opt-in strict mode, a consequential proposal nobody declared an origin for is denied
     * here — at the confirmation gate, before a receipt is issued or any other state is consumed.
     *
     * Asks the ledger directly rather than assembling the approver payload: the question is whether
     * a derivation was declared, and there is no approver to release anything to on a path that
     * ends in a denial. See ADR 0026 §5.
     */
    private function strictProvenanceDenial(Capability $capability, ActionEnvelope $envelope): ?Decision
    {
        if (! $this->strictProvenance->enabled() || ! $capability->isConsequential()) {
            return null;
        }

        $correlationId = $this->invocations->current();
        $declared = $correlationId !== null && $this->provenance->declaredUpstreamOf(
            $correlationId,
            ProposalAnchor::for($envelope->proposal->arguments),
        )->isDeclared();

        return $declared
            ? null
            : Decision::deny('No declared provenance for this proposal, and strict provenance is enabled.');
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

        // Gate 9.5 (#160): for capabilities whose effective posture requires it, commit the
        // write-ahead intent before the first mutating gate. Placement is the point — every
        // identity the record needs exists (steps 6-9 have run), and abandoning here is genuinely
        // fail-closed: no unit consumed, no receipt spent, no claim admitted, cost of one retry.
        $intentGate = $this->intentGate(
            $executionEvaluation,
            $this->targetIdentityFingerprint(
                $capability,
                $targetPolicy,
                $executionEvaluation,
                $executionEvaluation->target,
            ),
        );

        if ($intentGate instanceof ExecutionResult) {
            return $intentGate;
        }

        $intentId = $intentGate;

        $rateLimitEvaluation = $this->rateLimit($executionEvaluation, $intentId);

        if ($rateLimitEvaluation !== null && ! $rateLimitEvaluation->decision->permitsExecution()) {
            return ExecutionResult::denied($rateLimitEvaluation);
        }

        if ($confirmationRequired) {
            $approvalEvaluation = $this->approvalEvaluation(
                $executionEvaluation,
                $this->approvals->consume($executionEvaluation),
                ApprovalEvidencePhase::Consumption,
                $intentId,
            );

            if (! $approvalEvaluation->decision->permitsExecution()) {
                return ExecutionResult::denied($approvalEvaluation);
            }
        }

        return $this->executeAfterRateLimit(
            $executionEvaluation,
            fn (?ExecutionClaimAdmission $admission): mixed => $capability->execute(
                AuthorizedAction::fromExecutionEvaluation(
                    $executionEvaluation,
                    $admission?->claim()?->id,
                ),
            ),
            $intentId,
        );
    }

    /**
     * @param  ActionContext|callable(Request): ActionContext  $context
     */
    public function guard(Tool $tool, string $capability, ActionContext|callable $context): GuardedTool
    {
        return new GuardedTool($tool, $capability, $context, $this, $this->deniedMessage, $this->invocations, $this->approvalExecutions);
    }

    /**
     * @param  ActionContext|callable(Request): ActionContext  $context
     */
    public function bound(Tool $definition, string $capability, ActionContext|callable $context): BoundTool
    {
        $registered = $this->capabilities->get($capability);

        if (! $registered->isExecutable()) {
            throw CapabilityNotExecutable::named($registered->name);
        }

        return new BoundTool($definition, $capability, $context, $this, $this->deniedMessage, $this->invocations, $this->approvalExecutions);
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

    public function provenance(): ProvenanceLedger
    {
        return $this->provenance;
    }

    private function record(Evaluation $evaluation, ?string $intentId = null): Evaluation
    {
        $this->evidence->record(DecisionEvidence::fromEvaluation(
            $evaluation,
            $this->invocations->current(),
            $intentId,
        ));

        return $evaluation;
    }

    private function rateLimit(Evaluation $evaluation, ?string $intentId = null): ?Evaluation
    {
        $capability = $evaluation->capability;

        if ($capability === null || $capability->rateLimitPolicy() === null) {
            return null;
        }

        return $this->recordCommitted(new Evaluation(
            envelope: $evaluation->envelope,
            capability: $capability,
            target: $evaluation->target,
            decision: $this->rateLimits->consume($capability, $evaluation->envelope, $evaluation->target),
            stage: EvaluationStage::RateLimit,
        ), $intentId);
    }

    /** @param callable(): mixed $executor */
    private function execute(Evaluation $evaluation, callable $executor): ExecutionResult
    {
        // The unbound path runs the same intent gate (#160); it has no execution-target refresh,
        // so the intent records no target identity fingerprint.
        $intentGate = $this->intentGate($evaluation, null);

        if ($intentGate instanceof ExecutionResult) {
            return $intentGate;
        }

        $intentId = $intentGate;

        $rateLimitEvaluation = $this->rateLimit($evaluation, $intentId);

        if ($rateLimitEvaluation !== null && ! $rateLimitEvaluation->decision->permitsExecution()) {
            return ExecutionResult::denied($rateLimitEvaluation);
        }

        return $this->executeAfterRateLimit(
            $evaluation,
            fn (?ExecutionClaimAdmission $admission): mixed => $executor(),
            $intentId,
        );
    }

    /**
     * Run the write-ahead intent gate (#160): null when the effective posture does not require an
     * intent, the committed intent's id when it does and the write succeeded, or a denied
     * ExecutionResult when the write failed — with nothing consumed, at the cost of one retry.
     */
    private function intentGate(Evaluation $evaluation, ?string $executionTargetIdentityFingerprint): ExecutionResult|string|null
    {
        $capability = $evaluation->capability;

        if ($capability === null || ! $this->intents->required($capability)) {
            return null;
        }

        $admission = $this->intents->record(
            $evaluation,
            $executionTargetIdentityFingerprint,
            $this->invocations->current(),
        );
        $intentEvaluation = new Evaluation(
            envelope: $evaluation->envelope,
            capability: $capability,
            target: $evaluation->target,
            decision: $admission->decision,
            stage: EvaluationStage::Intent,
        );

        if ($admission->intent === null) {
            // A store outage under the lever means denied actions plus this alert — never an
            // unrecorded mutation, never orphaned state. The denial evidence keeps gates 1-9's
            // pre-mutation propagation behavior (ADR 0007).
            $this->events->dispatch(new ActionIntentWriteFailed(
                capability: $capability->name,
                invocationId: $this->invocations->current(),
                message: $admission->failureMessage ?? 'The intent store refused the write.',
            ));

            return ExecutionResult::denied($this->record($intentEvaluation));
        }

        // The intent row is committed operational state; its evidence mirror is deliberately
        // fail-open (EvidenceWriteFailed), the same posture as every outcome write (#153). The
        // mirror also references the intent id, so a verification query can pair them.
        $this->recordCommitted($intentEvaluation, $admission->intent->id);

        return $admission->intent->id;
    }

    /** @param callable(?ExecutionClaimAdmission): mixed $executor */
    private function executeAfterRateLimit(Evaluation $evaluation, callable $executor, ?string $intentId = null): ExecutionResult
    {
        $admission = $this->claimExecution($evaluation, $intentId);

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
            // The one place an executor runs, and therefore the one place the window opens: store
            // traffic before this line (claims, rate limits, evidence) and after it (finalization)
            // stays outside, which is what lets the evaluation harness treat a captured statement
            // as the executor's. See Contracts\ExecutionWindow.
            $window = $this->executionWindow === null ? null : ($this->executionWindow)();
            $output = $window === null
                ? $executor($admission)
                : $window->around(
                    $evaluation->envelope,
                    static fn (): mixed => $executor($admission),
                );
        } catch (Throwable $executionFailure) {
            if ($admission !== null) {
                try {
                    $this->recordClaimDecision(
                        $evaluation,
                        $this->executionClaims->markIndeterminate($admission),
                        $intentId,
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
            // ADR 0007's Update (#149) splits this block by layer. The claim transition is
            // operational state: if it diverges from reality the caller must be told, and told
            // that the side effect already happened. The evidence write below is a record about
            // that decision, and must not be able to report a completed execution as a failure.
            try {
                $decision = $this->executionClaims->complete($admission);
            } catch (Throwable $finalizationFailure) {
                throw ExecutionCompletedWithUnfinalizedClaim::fromFailure(
                    $output,
                    $admission->claim()?->id,
                    $finalizationFailure,
                );
            }

            $this->recordClaimDecision($evaluation, $decision, $intentId);
        }

        return ExecutionResult::executed($evaluation, $output);
    }

    private function approvalEvaluation(
        Evaluation $evaluation,
        ApprovalTransition $transition,
        ApprovalEvidencePhase $phase,
        ?string $intentId = null,
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

        // Only Consumption mutates; ApprovalManager::validate() is non-mutating, so gates 4 and 9
        // keep the original propagation behavior.
        $recorder = $phase === ApprovalEvidencePhase::Consumption
            ? $this->recordCommitted(...)
            : $this->record(...);

        return $recorder(new Evaluation(
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
        ), $intentId);
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

    private function claimExecution(Evaluation $evaluation, ?string $intentId = null): ?ExecutionClaimAdmission
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
        $this->recordClaimDecision($evaluation, $admission->decision, $intentId);

        return $admission;
    }

    /**
     * Record evidence for a gate that has already committed security-state, without letting an
     * evidence failure reach the caller or stop execution.
     *
     * See ADR 0007's Updates (#149) and (#153). Before a mutation, abandoning on an evidence
     * failure is fail-closed and costs only a retry, so gates 1-9 keep the original propagation
     * behavior. After one, abandoning moves state and tells the caller it did not.
     */
    private function recordCommitted(Evaluation $evaluation, ?string $intentId = null): Evaluation
    {
        try {
            return $this->record($evaluation, $intentId);
        } catch (Throwable $evidenceFailure) {
            $this->events->dispatch(new EvidenceWriteFailed(
                capability: $evaluation->envelope->proposal->capability,
                stage: $evaluation->stage->value,
                invocationId: $this->invocations->current(),
                message: $evidenceFailure->getMessage(),
            ));

            return $evaluation;
        }
    }

    private function recordClaimDecision(Evaluation $evaluation, Decision $decision, ?string $intentId = null): Evaluation
    {
        // Both call sites follow a committed mutation: gate 12 has admitted the claim, and gate 14
        // has transitioned it. Neither may let an evidence failure rewrite that.
        return $this->recordCommitted(new Evaluation(
            envelope: $evaluation->envelope,
            capability: $evaluation->capability,
            target: $evaluation->target,
            decision: $decision,
            stage: EvaluationStage::ExecutionClaim,
        ), $intentId);
    }
}
