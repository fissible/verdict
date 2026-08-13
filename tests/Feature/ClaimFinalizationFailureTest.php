<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Exceptions\ExecutionCompletedWithUnfinalizedClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimManager;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimResolution;
use Fissible\Verdict\VerdictManager;
use Illuminate\Support\Facades\Event;

final readonly class FinalizationTarget
{
    public function __construct(public int $id) {}
}

function finalizationEnvelope(string $toolCallId = 'tool-call-1'): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal(
            capability: 'orders.cancel-once',
            arguments: ['order_id' => 4242],
            idempotencyKey: $toolCallId,
        ),
        context: new ActionContext(72, ['tenant_id' => 'store-1']),
    );
}

/** @param callable(AuthorizedAction): string $executor */
function finalizationCapability(callable $executor): Capability
{
    return Capability::usingPolicy(
        name: 'orders.cancel-once',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): FinalizationTarget => new FinalizationTarget(
            (int) $envelope->proposal->arguments['order_id'],
        ),
    )->atMostOnce(ExecutionClaimPolicy::named(
        'cancel-order',
        fn (ActionEnvelope $envelope, FinalizationTarget $target): array => ['order_id' => $target->id],
    ))->executionTarget(acceptTestSnapshot('finalization-target-snapshot'))->executeUsing($executor);
}

beforeEach(function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
});

it('tells the caller the action ran when the claim is resolved out of band mid-execution', function (): void {
    $verdict = app(VerdictManager::class);
    $executions = 0;
    $claimId = null;

    $verdict->capability(finalizationCapability(
        function (AuthorizedAction $action) use (&$executions, &$claimId): string {
            $executions++;
            $claimId = $action->executionIdentity();

            // An operator running verdict:resolve-execution-claim against a claim that looks
            // stuck, while its executor is still running.
            app(ExecutionClaimManager::class)->resolve(
                (string) $claimId,
                ExecutionClaimResolution::Completed,
                'operator@example.com',
                'Looked stuck in the queue.',
            );

            return 'cancelled';
        },
    ));

    $caught = null;

    try {
        $verdict->runBound(finalizationEnvelope());
    } catch (ExecutionCompletedWithUnfinalizedClaim $exception) {
        $caught = $exception;
    }

    expect($executions)->toBe(1)
        ->and($caught)->not->toBeNull()
        // The side effect happened. The caller must be able to recover its result.
        ->and($caught?->output)->toBe('cancelled')
        // And must be able to reconcile without first hunting for the claim.
        ->and($caught?->claimId)->toBe($claimId)
        ->and($caught?->getPrevious())->not->toBeNull();
});

it('does not let a failed evidence write report a completed execution as a failure', function (): void {
    Event::fake([EvidenceWriteFailed::class]);

    $this->app->instance(EvidenceWriter::class, new class implements EvidenceWriter
    {
        public function record(DecisionEvidence $evidence): void
        {
            // Only the post-execution record, so admission and every earlier gate still write.
            if ($evidence->executionClaimStatus === 'completed') {
                throw new RuntimeException('The evidence store is unavailable.');
            }
        }

        public function recordRelease(ContextReleaseEvidence $evidence): void {}

        public function recordProvenance(ProvenanceEntry $entry): void {}

        public function recordDerivation(ProvenanceDerivation $derivation): void {}
    });

    $verdict = app(VerdictManager::class);
    $verdict->capability(finalizationCapability(fn (): string => 'cancelled'));

    $result = $verdict->runBound(finalizationEnvelope());

    // Losing evidence does not change what already executed (ADR 0007, decision point 2).
    expect($result->executed)->toBeTrue()
        ->and($result->output)->toBe('cancelled');

    Event::assertDispatched(EvidenceWriteFailed::class);
});

it('does not raise a LogicException for a concurrency-driven claim state change', function (): void {
    $verdict = app(VerdictManager::class);

    $verdict->capability(finalizationCapability(
        function (AuthorizedAction $action): string {
            app(ExecutionClaimManager::class)->resolve(
                (string) $action->executionIdentity(),
                ExecutionClaimResolution::Completed,
                'operator@example.com',
                'Looked stuck in the queue.',
            );

            return 'cancelled';
        },
    ));

    // An operator resolving a claim concurrently is a concurrency event, not a programming error.
    expect(fn (): mixed => $verdict->runBound(finalizationEnvelope()))
        ->not->toThrow(LogicException::class);
});
