<?php

declare(strict_types=1);

namespace Fissible\Verdict;

use Closure;
use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Actions\InvocationContext;
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
use Fissible\Verdict\Evaluation\EvaluationReadPredicateSuppression;
use Fissible\Verdict\Evaluation\ResourceCheckpointCapture;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\Verdict\Evidence\NullRecorderWarning;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\Exceptions\CapabilityNotExecutable;
use Fissible\Verdict\Exceptions\ExecutionClaimFinalizationFailed;
use Fissible\Verdict\Exceptions\ExecutionCompletedWithUnfinalizedClaim;
use Fissible\Verdict\Exceptions\RequireReviewNotImplemented;
use Fissible\Verdict\Exceptions\ReviewRequestNotIssued;
use Fissible\Verdict\Exceptions\TargetNotResolvable;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimAdmission;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimManager;
use Fissible\Verdict\Intents\ActionIntentManager;
use Fissible\Verdict\Intents\Events\ActionIntentWriteFailed;
use Fissible\Verdict\Intents\IntentGateOutcome;
use Fissible\Verdict\LaravelAi\BoundTool;
use Fissible\Verdict\LaravelAi\GuardedTool;
use Fissible\Verdict\RateLimits\RateLimitManager;
use Fissible\Verdict\Reviews\ReviewManager;
use Fissible\Verdict\Reviews\ReviewOutcome;
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
        private EvaluationReadPredicateSuppression $evaluationReadSuppression,
        /**
         * Resolved per execution, never at construction: a provider that type-hints this manager
         * in boot() constructs it before any evaluation harness runs, and an eagerly-captured
         * window would freeze as null — every filtered-permit trial silently unmeasured.
         *
         * @var (Closure(): ?ExecutionWindow)|null
         */
        private ?Closure $executionWindow = null,
        /** @var (Closure(): ?ResourceCheckpointCapture)|null */
        private ?Closure $resourceCheckpointCapture = null,
        /** @var (Closure(): ?ReviewManager)|null */
        private ?Closure $reviewManager = null,
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

        $decision = $this->authorizerDecision($this->authorizer->decide($capability, $envelope, $target));

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
        $review = $this->issueReviewOrReserve($evaluation);

        if ($review !== null) {
            return $review;
        }

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
            Disposition::RequireReview,
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
        $reviewRequired = $proposalEvaluation->decision->disposition === Disposition::RequireReview;
        $reviews = null;

        if ($reviewRequired) {
            $reviews = $this->reviewManager === null ? null : ($this->reviewManager)();

            if ($reviews === null) {
                throw RequireReviewNotImplemented::forCapability($envelope->proposal->capability);
            }

            $transition = $reviews->validate($proposalEvaluation);

            if ($transition->outcome !== ReviewOutcome::Approved
                && $transition->outcome !== ReviewOutcome::NotFound) {
                return $this->reviewPending($proposalEvaluation);
            }
        }

        if ($confirmationRequired) {
            $approvalEvaluation = $this->approvalEvaluation(
                $proposalEvaluation,
                $this->approvals->validate($proposalEvaluation),
                ApprovalEvidencePhase::ProposalValidation,
                // Gate 4 precedes the intent gate; there is no intent to reference yet.
                intentId: null,
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
            decision: $this->authorizerDecision($this->authorizer->decide($capability, $envelope, $refreshEvaluation->target)),
            stage: EvaluationStage::Execution,
        ));

        $reviewAdmissionRequired = $reviewRequired
            && $executionEvaluation->decision->disposition !== Disposition::Deny;

        if (! $reviewRequired
            && $executionEvaluation->decision->disposition === Disposition::RequireReview
            && ($this->reviewManager === null || ($this->reviewManager)() === null)) {
            throw RequireReviewNotImplemented::forCapability($envelope->proposal->capability);
        }

        if (! $executionEvaluation->decision->permitsExecution() && ! $reviewAdmissionRequired) {
            return ExecutionResult::denied($executionEvaluation);
        }

        if ($confirmationRequired) {
            $approvalEvaluation = $this->approvalEvaluation(
                $executionEvaluation,
                $this->approvals->validate($executionEvaluation),
                ApprovalEvidencePhase::ExecutionValidation,
                // Gate 9 precedes the intent gate; there is no intent to reference yet.
                intentId: null,
            );

            if (! $approvalEvaluation->decision->permitsExecution()) {
                return ExecutionResult::denied($approvalEvaluation);
            }
        }

        if ($reviewAdmissionRequired) {
            $transition = $reviews->validate($executionEvaluation);

            if ($transition->outcome === ReviewOutcome::NotFound) {
                $review = $this->issueReviewOrReserve($executionEvaluation);

                if ($review !== null) {
                    return $review;
                }

                return $this->reviewPending($executionEvaluation);
            }

            if ($transition->outcome !== ReviewOutcome::Approved) {
                return $this->reviewPending($executionEvaluation);
            }
        }

        // Gate 9.5 (#160): for capabilities whose effective posture requires it, commit the
        // write-ahead intent before the first mutating gate. Placement is the point — every
        // identity the record needs exists (steps 6-9 have run), and abandoning here is genuinely
        // fail-closed: no unit consumed, no receipt spent, no claim admitted, cost of one retry.
        //
        // The execution-target fingerprint is read back from the refresh decision, never
        // recomputed: recomputing would consult the application's identity resolver a third time
        // on every action — lever on or off — and a non-pure resolver could hand the intent a
        // fingerprint the pipeline never validated.
        $refreshedFingerprint = $refreshEvaluation->decision->metadata['execution_target_identity_fingerprint'] ?? null;
        $intent = $this->intentGate(
            $executionEvaluation,
            is_string($refreshedFingerprint) ? $refreshedFingerprint : null,
        );

        if ($intent->denial !== null) {
            return $intent->denial;
        }

        $rateLimitEvaluation = $this->rateLimit($executionEvaluation, $intent->intentId);

        if ($rateLimitEvaluation !== null && ! $rateLimitEvaluation->decision->permitsExecution()) {
            return ExecutionResult::denied($rateLimitEvaluation);
        }

        if ($confirmationRequired) {
            $approvalEvaluation = $this->approvalEvaluation(
                $executionEvaluation,
                $this->approvals->consume($executionEvaluation),
                ApprovalEvidencePhase::Consumption,
                $intent->intentId,
            );

            if (! $approvalEvaluation->decision->permitsExecution()) {
                return ExecutionResult::denied($approvalEvaluation);
            }
        }

        $admittedExecutionEvaluation = $executionEvaluation;

        if ($reviewAdmissionRequired) {
            $transition = $reviews->consume($executionEvaluation);

            if ($transition->outcome !== ReviewOutcome::Consumed) {
                return $this->reviewPending($executionEvaluation);
            }

            $request = $transition->request;

            if ($request === null) {
                return $this->reviewPending($executionEvaluation);
            }

            $reviewAdmissionEvaluation = $this->record(new Evaluation(
                envelope: $executionEvaluation->envelope,
                capability: $executionEvaluation->capability,
                target: $executionEvaluation->target,
                decision: Decision::requireReview($executionEvaluation->decision->reason, [
                    'review_request_id' => $request->id,
                    'review_request_fingerprint' => $request->bindingFingerprint,
                    'review_admitted' => true,
                ]),
                stage: EvaluationStage::Review,
            ));

            $admittedExecutionEvaluation = new Evaluation(
                envelope: $executionEvaluation->envelope,
                capability: $executionEvaluation->capability,
                target: $executionEvaluation->target,
                decision: Decision::reviewAdmitted($executionEvaluation->decision->reason),
                stage: EvaluationStage::Execution,
            );
        }

        $result = $this->executeAfterRateLimit(
            $admittedExecutionEvaluation,
            fn (?ExecutionClaimAdmission $admission): mixed => $capability->execute(
                AuthorizedAction::fromExecutionEvaluation(
                    $admittedExecutionEvaluation,
                    $admission?->claim()?->id,
                ),
            ),
            $intent->intentId,
        );

        return $reviewAdmissionRequired && $result->executed
            ? ExecutionResult::executed($reviewAdmissionEvaluation, $result->output)
            : $result;
    }

    /**
     * @param  ActionContext|callable(Request): ActionContext  $context
     */
    public function guard(Tool $tool, string $capability, ActionContext|callable $context): GuardedTool
    {
        return new GuardedTool(
            $tool,
            $capability,
            $context,
            fn (): VerdictManager => app(VerdictManager::class),
            $this->deniedMessage,
            fn (): InvocationContext => app(InvocationContext::class),
            fn (): ApprovalExecutionContext => app(ApprovalExecutionContext::class),
        );
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

        return new BoundTool(
            $definition,
            $capability,
            $context,
            fn (): VerdictManager => app(VerdictManager::class),
            $this->deniedMessage,
            fn (): InvocationContext => app(InvocationContext::class),
            fn (): ApprovalExecutionContext => app(ApprovalExecutionContext::class),
        );
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

    private function authorizerDecision(Decision $decision): Decision
    {
        return $decision->disposition === Disposition::ReviewAdmitted
            ? Decision::deny('An authorizer may not self-admit a review.')
            : $decision;
    }

    private function issueReviewOrReserve(Evaluation $evaluation): ?ExecutionResult
    {
        if ($evaluation->decision->disposition !== Disposition::RequireReview) {
            return null;
        }

        $manager = $this->reviewManager === null ? null : ($this->reviewManager)();

        if ($manager === null) {
            throw RequireReviewNotImplemented::forCapability($evaluation->envelope->proposal->capability);
        }

        $transition = $manager->issue($evaluation);

        if (! $transition->succeeded() || $transition->request === null) {
            throw ReviewRequestNotIssued::forCapability($evaluation->envelope->proposal->capability);
        }

        $request = $transition->request;
        $reviewEvaluation = $this->record(new Evaluation(
            envelope: $evaluation->envelope,
            capability: $evaluation->capability,
            target: $evaluation->target,
            decision: Decision::requireReview('This action requires human review; a review request is pending a decision.', [
                'review_request_id' => $request->id,
                'review_request_fingerprint' => $request->bindingFingerprint,
            ]),
            stage: EvaluationStage::Review,
        ));

        return ExecutionResult::denied($reviewEvaluation);
    }

    private function reviewPending(Evaluation $evaluation): ExecutionResult
    {
        return ExecutionResult::denied($this->record(new Evaluation(
            envelope: $evaluation->envelope,
            capability: $evaluation->capability,
            target: $evaluation->target,
            decision: Decision::requireReview('This action requires human review; a review request is pending a decision.'),
            stage: EvaluationStage::Review,
        )));
    }

    private function rateLimit(Evaluation $evaluation, ?string $intentId): ?Evaluation
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
        $intent = $this->intentGate($evaluation, null);

        if ($intent->denial !== null) {
            return $intent->denial;
        }

        $rateLimitEvaluation = $this->rateLimit($evaluation, $intent->intentId);

        if ($rateLimitEvaluation !== null && ! $rateLimitEvaluation->decision->permitsExecution()) {
            return ExecutionResult::denied($rateLimitEvaluation);
        }

        return $this->executeAfterRateLimit(
            $evaluation,
            fn (?ExecutionClaimAdmission $admission): mixed => $executor(),
            $intent->intentId,
        );
    }

    /**
     * Run the write-ahead intent gate (#160).
     *
     * Reports through {@see IntentGateOutcome} rather than a union, so a caller cannot mistake a
     * refusal for an intent id: proceeding with no intent (the effective posture does not require
     * one), proceeding with the committed intent's id, or a denial to return verbatim — nothing
     * consumed, at the cost of one retry.
     */
    private function intentGate(Evaluation $evaluation, ?string $executionTargetIdentityFingerprint): IntentGateOutcome
    {
        $capability = $evaluation->capability;

        if ($capability === null || ! $this->intents->required($capability)) {
            return IntentGateOutcome::proceed(null);
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

            return IntentGateOutcome::refused(ExecutionResult::denied($this->record($intentEvaluation)));
        }

        // The intent row is committed operational state; its evidence mirror is deliberately
        // fail-open (EvidenceWriteFailed), the same posture as every outcome write (#153). The
        // mirror also references the intent id, so a verification query can pair them.
        $this->recordCommitted($intentEvaluation, $admission->intent->id);

        return IntentGateOutcome::proceed($admission->intent->id);
    }

    /** @param callable(?ExecutionClaimAdmission): mixed $executor */
    private function executeAfterRateLimit(Evaluation $evaluation, callable $executor, ?string $intentId): ExecutionResult
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
            $capture = $this->resourceCheckpointCapture === null ? null : ($this->resourceCheckpointCapture)();
            $checkpointSequence = null;

            if ($capture !== null && $evaluation->capability !== null) {
                $checkpointSequence = $capture->checkpoint($evaluation->envelope, $evaluation->capability, $evaluation->target);
            }

            $run = fn (): mixed => $executor($admission);
            $window = $this->executionWindow === null ? null : ($this->executionWindow)();
            $output = $window === null
                ? $run()
                : $window->around(
                    $evaluation->envelope,
                    $run,
                );

            if ($capture !== null && $checkpointSequence !== null) {
                $capture->commit($evaluation->envelope, $checkpointSequence);
            }
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
        } elseif ($intentId !== null) {
            // A claim-less intent-gated run has no finalization row, so without this record the
            // scheduled-verification query would flag every healthy success as a gap forever.
            // Claim finalization is the account when a claim policy exists; this is the account
            // otherwise — an admission-side belief around a successful executor return, fail-open
            // like every post-mutation write. An executor throw deliberately leaves no conclusion:
            // for the claim-less shape, that flagged gap is the lever working.
            $this->recordCommitted(new Evaluation(
                envelope: $evaluation->envelope,
                capability: $evaluation->capability,
                target: $evaluation->target,
                decision: Decision::permit('The write-ahead intent was concluded around a successful executor return.'),
                stage: EvaluationStage::IntentConcluded,
            ), $intentId);
        }

        return ExecutionResult::executed($evaluation, $output);
    }

    private function approvalEvaluation(
        Evaluation $evaluation,
        ApprovalTransition $transition,
        ApprovalEvidencePhase $phase,
        ?string $intentId,
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
        return $this->evaluationReadSuppression->whileActive(fn (): string => ArgumentFingerprint::make([
            'capability' => $capability->name,
            'target_policy' => $policy->name,
            'identity' => $policy->identity($evaluation->envelope, $target),
        ]));
    }

    private function claimExecution(Evaluation $evaluation, ?string $intentId): ?ExecutionClaimAdmission
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
     *
     * `$intentId` deliberately has no default: every post-gate write runs after the intent gate,
     * so each call site must decide its intent reference. A silently-null reference would
     * manufacture false gaps in the scheduled-verification query (#160).
     */
    private function recordCommitted(Evaluation $evaluation, ?string $intentId): Evaluation
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

    private function recordClaimDecision(Evaluation $evaluation, Decision $decision, ?string $intentId): Evaluation
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
