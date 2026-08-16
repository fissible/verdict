<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\TargetSource;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\VerdictManager;

/**
 * #192 / ADR 0025. A resolver that receives an `ActionContext` cannot read the proposal, so an
 * injected argument cannot redirect which record is acted on. The property is enforced by parameter
 * types rather than declared, and the path a decision took is recorded so an auditor can find the
 * capabilities where redirection remains possible.
 *
 * #187 demonstrated the gap; this is the mechanism.
 */
final readonly class ResolvedOrder
{
    public function __construct(public int $id) {}
}

function targetSourceEnvelope(int $proposedOrderId, int $contextOrderId): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal(
            capability: 'orders.view-target',
            arguments: ['order_id' => $proposedOrderId],
            idempotencyKey: 'tool-call-1',
        ),
        // Both orders belong to the same actor: this is the inside-authority case, where every
        // authorization check passes and only provenance decides the record.
        context: new ActionContext(72, ['order_id' => $contextOrderId]),
    );
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

it('resolves the context target and ignores an injected argument', function (): void {
    $acted = null;

    app(VerdictManager::class)->capability(
        Capability::usingPolicyForContextTarget(
            name: 'orders.view-target',
            ability: 'view',
            resolveTarget: fn (ActionContext $context): ResolvedOrder => new ResolvedOrder(
                (int) $context->metadata['order_id'],
            ),
        )->executionTarget(acceptTestSnapshot('context-target-snapshot'))
            ->executeUsing(function ($action) use (&$acted): string {
                $acted = $action->target->id;

                return 'viewed';
            }),
    );

    // The model asks for 1001; the application knows the session is about 1002.
    app(VerdictManager::class)->runBound(targetSourceEnvelope(proposedOrderId: 1001, contextOrderId: 1002));

    expect($acted)->toBe(1002);
});

it('hands the context resolver no access to the proposal', function (): void {
    $received = null;

    app(VerdictManager::class)->capability(
        Capability::usingPolicyForContextTarget(
            name: 'orders.view-target',
            ability: 'view',
            resolveTarget: function (mixed $argument) use (&$received): ResolvedOrder {
                $received = $argument;

                return new ResolvedOrder(1002);
            },
        )->executionTarget(acceptTestSnapshot('context-target-snapshot'))
            ->executeUsing(fn (): string => 'viewed'),
    );

    app(VerdictManager::class)->runBound(targetSourceEnvelope(1001, 1002));

    // The structural guarantee: the resolver is handed the context, so the proposal is not in
    // scope to be read. A declaration could be contradicted on the next line; this cannot.
    expect($received)->toBeInstanceOf(ActionContext::class)
        ->and($received)->not->toBeInstanceOf(ActionEnvelope::class);
});

it('records which resolution path a decision used', function (): void {
    app(VerdictManager::class)->capability(
        Capability::usingPolicyForContextTarget(
            name: 'orders.view-target',
            ability: 'view',
            resolveTarget: fn (ActionContext $context): ResolvedOrder => new ResolvedOrder(1002),
        )->executionTarget(acceptTestSnapshot('context-target-snapshot'))
            ->executeUsing(fn (): string => 'viewed'),
    );

    app(VerdictManager::class)->runBound(targetSourceEnvelope(1001, 1002));

    $evidence = app(EvidenceRecorder::class);
    expect($evidence)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    $decisions = collect($evidence->all())->filter(fn ($row): bool => $row->targetSource !== null)->values();

    expect($decisions)->not->toBeEmpty()
        ->and($decisions[0]->targetSource)->toBe(TargetSource::Context->value);
});

it('records a proposal-resolved capability as proposal-resolved', function (): void {
    app(VerdictManager::class)->capability(
        Capability::usingPolicy(
            name: 'orders.view-target',
            ability: 'view',
            resolveTarget: fn (ActionEnvelope $envelope): ResolvedOrder => new ResolvedOrder(
                (int) $envelope->proposal->arguments['order_id'],
            ),
        )->executionTarget(acceptTestSnapshot('context-target-snapshot'))
            ->executeUsing(fn (): string => 'viewed'),
    );

    app(VerdictManager::class)->runBound(targetSourceEnvelope(1001, 1002));

    $decisions = collect(app(EvidenceRecorder::class)->all())
        ->filter(fn ($row): bool => $row->targetSource !== null)
        ->values();

    // ADR 0025: the field names the constructor that was used, not a verified property of the
    // closure body. A usingPolicy() capability reads as proposal-resolved even if its resolver
    // happens to touch only context — Verdict cannot see inside the closure.
    expect($decisions[0]->targetSource)->toBe(TargetSource::Proposal->value);
});
