<?php

declare(strict_types=1);

namespace Fissible\Verdict\Testing;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovedToolCalls;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;
use Fissible\Verdict\Targets\ExecutionTargetStrategy;
use Fissible\Verdict\VerdictManager;
use Throwable;

/**
 * Framework-agnostic assertions for an application's real Verdict capability wiring.
 *
 * Construct one kit for one freshly configured capability in an isolated test. The caller owns
 * its targets, authorization policy, binding mutation, and observable side effects; this class
 * only drives Verdict's protected execution path and verifies its security outcomes.
 */
final readonly class CapabilitySecurityTestKit
{
    private function __construct(
        private VerdictManager $verdict,
        private Capability $registeredCapability,
        private string $capability,
        private ApprovalStatusReader $approvalStatus,
    ) {}

    public static function for(VerdictManager $verdict, string $capability): self
    {
        return new self(
            $verdict,
            $verdict->registeredCapability($capability),
            $capability,
            app(ApprovalStatusReader::class),
        );
    }

    /**
     * @param  callable(): bool  $assertPolicyWasApplied
     * @param  callable(): bool  $assertDeniedSideEffects
     */
    public function assertPolicyDenial(
        ActionEnvelope $deniedEnvelope,
        callable $assertPolicyWasApplied,
        callable $assertDeniedSideEffects,
    ): void {
        $this->assertEnvelope($deniedEnvelope, 'policy-denial-fixture');
        $result = $this->verdict->runBound($deniedEnvelope);

        $this->assertDeniedAt($result->executed, $result->evaluation->stage, EvaluationStage::Proposal, 'policy-denial');
        $this->assert($result->evaluation->capability !== null && $result->evaluation->target !== null, 'policy-denial-target');
        $this->assertSideEffects($assertPolicyWasApplied, 'policy-denial-policy');
        $this->assertSideEffects($assertDeniedSideEffects, 'policy-denial-side-effects');
    }

    /** @param callable(): bool $assertRefreshedSideEffects */
    public function assertRefreshedTargetSubstitution(
        ActionEnvelope $envelope,
        callable $assertRefreshedSideEffects,
    ): void {
        $this->assertEnvelope($envelope, 'refreshed-target-fixture');
        $this->assert(
            $this->registeredCapability->executionTargetPolicy()?->strategy === ExecutionTargetStrategy::Refresh,
            'refreshed-target-policy',
        );
        $result = $this->verdict->runBound($envelope);

        $this->assert($result->executed, 'refreshed-target-substitution');
        $this->assert($result->evaluation->stage === EvaluationStage::Execution, 'refreshed-target-stage');
        $this->assertSideEffects($assertRefreshedSideEffects, 'refreshed-target-side-effects');
    }

    /**
     * @param  callable(): void  $invalidateBinding
     * @param  callable(): bool  $assertDeniedSideEffects
     */
    public function assertApprovalBindingInvalidation(
        ActionEnvelope $envelope,
        string $approvedBy,
        callable $invalidateBinding,
        callable $assertDeniedSideEffects,
    ): void {
        $this->assertEnvelope($envelope, 'approval-binding-fixture');
        $this->assert(
            is_string($envelope->proposal->idempotencyKey) && trim($envelope->proposal->idempotencyKey) !== '',
            'approval-binding-idempotency-key',
        );
        $decision = $this->verdict->requestConfirmation($envelope);
        $this->assert($decision?->disposition === Disposition::RequireConfirmation, 'approval-binding-issued');

        $toolCallId = $envelope->proposal->idempotencyKey;
        $challenge = $toolCallId === null ? null : $this->verdict->approvals()->challengeForToolCall($toolCallId);
        $this->assert($challenge !== null, 'approval-binding-challenge');

        if ($challenge === null) {
            return;
        }

        $transition = $this->verdict->approvals()->approve(
            $challenge->receiptId,
            $challenge->toolCallId,
            $approvedBy,
        );
        $this->assert($transition->outcome === ApprovalOutcome::Approved, 'approval-binding-approved');

        $invalidateBinding();

        // `not_found` after the binding changes also results when an application callback loses
        // the receipt, so establish that this exact approved receipt survived before re-running.
        $status = $this->approvalStatus->statusFor($challenge->receiptId);
        $this->assert($status?->status === ApprovalReceiptStatus::Approved, 'approval-binding-receipt-retained');

        $result = $this->verdict->approvals()->withinApprovedToolCalls(
            ApprovedToolCalls::of([$challenge->toolCallId]),
            fn () => $this->verdict->runBound($envelope),
        );

        $this->assertDeniedAt($result->executed, $result->evaluation->stage, EvaluationStage::Approval, 'approval-binding-invalidation');
        $this->assert(
            ($result->evaluation->decision->metadata['approval_outcome'] ?? null) === ApprovalOutcome::NotFound->value,
            'approval-binding-invalidated-receipt',
        );
        $this->assertSideEffects($assertDeniedSideEffects, 'approval-binding-side-effects');
    }

    /** @param callable(): bool $assertExpectedSideEffects */
    public function assertExecutionClaimDuplicateAdmission(
        ActionEnvelope $firstEnvelope,
        ActionEnvelope $duplicateEnvelope,
        callable $assertExpectedSideEffects,
    ): void {
        $this->assertEnvelope($firstEnvelope, 'execution-claim-first-fixture');
        $this->assertEnvelope($duplicateEnvelope, 'execution-claim-duplicate-fixture');
        $first = $this->verdict->runBound($firstEnvelope);
        $duplicate = $this->verdict->runBound($duplicateEnvelope);

        $this->assert($first->executed, 'execution-claim-first-admission');
        $this->assert($first->evaluation->stage === EvaluationStage::Execution, 'execution-claim-first-stage');
        $this->assertDeniedAt(
            $duplicate->executed,
            $duplicate->evaluation->stage,
            EvaluationStage::ExecutionClaim,
            'execution-claim-duplicate-admission',
        );
        $this->assertSideEffects($assertExpectedSideEffects, 'execution-claim-side-effects');
    }

    /** @param  list<ActionEnvelope>  $additionalPermittedEnvelopes */
    public function assertRateLimitEnforcement(
        ActionEnvelope $firstPermittedEnvelope,
        array $additionalPermittedEnvelopes,
        ActionEnvelope $deniedEnvelope,
        callable $assertExpectedSideEffects,
    ): void {
        foreach ([$firstPermittedEnvelope, ...$additionalPermittedEnvelopes] as $envelope) {
            $this->assertEnvelope($envelope, 'rate-limit-permitted-fixture');
            $result = $this->verdict->runBound($envelope);
            $this->assert($result->executed && $result->evaluation->stage === EvaluationStage::Execution, 'rate-limit-permitted');
        }

        $this->assertEnvelope($deniedEnvelope, 'rate-limit-denied-fixture');
        $denied = $this->verdict->runBound($deniedEnvelope);
        $this->assertDeniedAt($denied->executed, $denied->evaluation->stage, EvaluationStage::RateLimit, 'rate-limit-enforcement');
        $this->assertSideEffects($assertExpectedSideEffects, 'rate-limit-side-effects');
    }

    /**
     * @param  class-string<Throwable>  $expectedException
     * @param  callable(): bool  $assertFailureSideEffects
     */
    public function assertExecutorFailureLeavesIndeterminateClaim(
        ActionEnvelope $envelope,
        string $expectedException,
        callable $assertFailureSideEffects,
    ): void {
        $this->assertEnvelope($envelope, 'executor-failure-fixture');
        $existingClaims = [];

        foreach ($this->verdict->executionClaims()->unresolved() as $claim) {
            $existingClaims[$claim->id] = true;
        }

        try {
            $this->verdict->runBound($envelope);
        } catch (Throwable $failure) {
            $this->assert($failure instanceof $expectedException, 'executor-failure-type');

            $indeterminate = false;

            foreach ($this->verdict->executionClaims()->unresolved() as $claim) {
                if (! isset($existingClaims[$claim->id])
                    && $claim->capability === $this->capability
                    && $claim->status === ExecutionClaimStatus::Indeterminate) {
                    $indeterminate = true;

                    break;
                }
            }

            $this->assert($indeterminate, 'executor-failure-indeterminate-claim');
            $this->assertSideEffects($assertFailureSideEffects, 'executor-failure-side-effects');

            return;
        }

        $this->fail('executor-failure-thrown');
    }

    /** @param callable(): bool $assertion */
    private function assertSideEffects(callable $assertion, string $invariant): void
    {
        $this->assert($assertion() === true, $invariant);
    }

    private function assertEnvelope(ActionEnvelope $envelope, string $invariant): void
    {
        $this->assert($envelope->proposal->capability === $this->capability, $invariant);
    }

    private function assertDeniedAt(
        bool $executed,
        EvaluationStage $actualStage,
        EvaluationStage $expectedStage,
        string $invariant,
    ): void {
        $this->assert(! $executed && $actualStage === $expectedStage, $invariant);
    }

    private function assert(bool $condition, string $invariant): void
    {
        if (! $condition) {
            $this->fail($invariant);
        }
    }

    private function fail(string $invariant): never
    {
        throw CapabilitySecurityAssertionFailed::forInvariant($this->capability, $invariant);
    }
}
