<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\Events\ConsequentialActionUnrecorded;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\VerdictManager;
use Illuminate\Support\Facades\Event;

/**
 * #194 (option b). A capability requiring confirmation or at-most-once execution, running under the
 * shipped no-op recorder, emits a single once-per-process warning — worded as the *class* of
 * capabilities, never one named capability. Evidence is not an authorization gate (ADR 0007), so
 * this warns; it never blocks and never throws.
 *
 * @verdict-claim evidence.null-recorder-warned
 */
final readonly class NullWarnTarget
{
    public function __construct(public int $id) {}
}

function nullWarnEnvelope(): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal(
            capability: 'orders.cancel-consequential',
            arguments: ['order_id' => 5],
            idempotencyKey: 'k1',
        ),
        context: new ActionContext(72, ['tenant_id' => 't1']),
    );
}

function nullWarnConsequentialCapability(): Capability
{
    return Capability::usingPolicy(
        name: 'orders.cancel-consequential',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $e): NullWarnTarget => new NullWarnTarget((int) $e->proposal->arguments['order_id']),
    )->requiresConfirmation(
        bindUsing: fn (ActionEnvelope $e, NullWarnTarget $t): array => ['order_id' => $t->id],
        reason: 'confirm',
        ttlSeconds: 300,
    );
}

function nullWarnOrdinaryCapability(): Capability
{
    return Capability::usingPolicy(
        name: 'orders.view-ordinary',
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $e): NullWarnTarget => new NullWarnTarget((int) $e->proposal->arguments['order_id']),
    )->executeUsing(fn (): string => 'ok');
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

it('warns once per process when a consequential capability runs under the no-op recorder', function (): void {
    $this->app->instance(EvidenceWriter::class, new NullEvidenceRecorder);
    Event::fake([ConsequentialActionUnrecorded::class]);

    $verdict = app(VerdictManager::class)->capability(nullWarnConsequentialCapability());
    $verdict->evaluate(nullWarnEnvelope());
    $verdict->evaluate(nullWarnEnvelope());   // a second consequential action, same process

    Event::assertDispatchedTimes(ConsequentialActionUnrecorded::class, 1);
});

it('does not warn when a consequential capability runs under a real recorder', function (): void {
    $this->app->instance(EvidenceWriter::class, new InMemoryEvidenceRecorder);
    Event::fake([ConsequentialActionUnrecorded::class]);

    app(VerdictManager::class)->capability(nullWarnConsequentialCapability())->evaluate(nullWarnEnvelope());

    Event::assertNotDispatched(ConsequentialActionUnrecorded::class);
});

it('does not warn for an ordinary capability even under the no-op recorder', function (): void {
    $this->app->instance(EvidenceWriter::class, new NullEvidenceRecorder);
    Event::fake([ConsequentialActionUnrecorded::class]);

    app(VerdictManager::class)->capability(nullWarnOrdinaryCapability())->evaluate(nullWarnEnvelope());

    Event::assertNotDispatched(ConsequentialActionUnrecorded::class);
});
