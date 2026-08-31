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
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimManager;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Support\Facades\Event;

final readonly class GateTarget
{
    public function __construct(public int $id) {}
}

function gateEnvelope(string $toolCallId = 'tool-call-1'): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal(
            capability: 'orders.gate',
            arguments: ['order_id' => 7001],
            idempotencyKey: $toolCallId,
        ),
        context: new ActionContext(72, ['tenant_id' => 'store-1']),
    );
}

function gateCapability(): Capability
{
    return Capability::usingPolicy(
        name: 'orders.gate',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): GateTarget => new GateTarget(
            (int) $envelope->proposal->arguments['order_id'],
        ),
    )->executionTarget(acceptTestSnapshot('gate-target-snapshot'))
        ->executeUsing(fn (AuthorizedAction $action): string => 'cancelled');
}

/** Fail only the evidence write for the named stage; every other gate still records. */
function failEvidenceAtStage(string $stage): void
{
    app()->instance(EvidenceWriter::class, new class($stage) implements EvidenceWriter
    {
        public function __construct(private string $stage) {}

        public function record(DecisionEvidence $evidence): void
        {
            if ($evidence->stage === $this->stage) {
                throw new RuntimeException('The evidence store is unavailable.');
            }
        }

        public function recordRelease(ContextReleaseEvidence $evidence): void {}

        public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void {}

        public function recordProvenance(ProvenanceEntry $entry): void {}

        public function recordDerivation(ProvenanceDerivation $derivation): void {}
    });
}

beforeEach(function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
    Event::fake([EvidenceWriteFailed::class]);
});

it('executes when the rate-limit gate records no evidence', function (): void {
    failEvidenceAtStage('rate_limit');

    $verdict = app(VerdictManager::class);
    $verdict->capability(gateCapability()->rateLimit(RateLimitPolicy::fixedWindow(
        name: 'orders-per-minute',
        limit: 5,
        windowSeconds: 60,
        keyUsing: fn (ActionEnvelope $envelope, GateTarget $target): array => ['order_id' => $target->id],
    )));

    $result = $verdict->runBound(gateEnvelope());

    // The unit was consumed. An evidence failure must not veto an action every gate permitted.
    expect($result->executed)->toBeTrue()
        ->and($result->output)->toBe('cancelled');

    Event::assertDispatched(EvidenceWriteFailed::class);
});

it('does not strand an execution claim when the admission gate records no evidence', function (): void {
    failEvidenceAtStage('execution_claim');

    $verdict = app(VerdictManager::class);
    $verdict->capability(gateCapability()->atMostOnce(ExecutionClaimPolicy::named(
        'cancel-order',
        fn (ActionEnvelope $envelope, GateTarget $target): array => ['order_id' => $target->id],
    )));

    $result = $verdict->runBound(gateEnvelope());

    expect($result->executed)->toBeTrue()
        ->and($result->output)->toBe('cancelled');

    // The point of this fix: the claim reaches finalization instead of being left admitted
    // forever, blocking every future duplicate of the binding.
    $unresolved = app(ExecutionClaimManager::class)->unresolved();

    expect($unresolved)->toBe([]);

    Event::assertDispatched(EvidenceWriteFailed::class);
});

it('still records a claim that was genuinely denied as a duplicate', function (): void {
    $verdict = app(VerdictManager::class);
    $verdict->capability(gateCapability()->atMostOnce(ExecutionClaimPolicy::named(
        'cancel-order',
        fn (ActionEnvelope $envelope, GateTarget $target): array => ['order_id' => $target->id],
    )));

    $first = $verdict->runBound(gateEnvelope('call-a'));
    $duplicate = $verdict->runBound(gateEnvelope('call-b'));

    // The operational outcome still gates. Only the record of it lost that power.
    expect($first->executed)->toBeTrue()
        ->and($duplicate->executed)->toBeFalse()
        ->and($duplicate->evaluation->decision->reason)->toBe('Logical operation was already completed.');
});

it('propagates an evidence failure at a non-mutating gate', function (): void {
    failEvidenceAtStage('proposal');

    $verdict = app(VerdictManager::class);
    $verdict->capability(gateCapability());

    // Before any mutation, abandoning is fail-closed and costs only a retry, so the original
    // propagation behavior is deliberately kept.
    expect(fn (): mixed => $verdict->runBound(gateEnvelope()))
        ->toThrow(RuntimeException::class, 'The evidence store is unavailable.');
});
