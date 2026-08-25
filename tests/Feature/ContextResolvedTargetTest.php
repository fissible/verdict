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
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\VerdictManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

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

it('preserves the target source through every builder method', function (): void {
    // Capability is immutable and every builder returns a new instance. Threading targetSource
    // through each rebuild by hand missed one — configurationVersion() — because its rebuild sets
    // a new value rather than copying $this->configurationVersion, so a search for the copied form
    // did not find it. This guard is reflective so a seventh builder cannot regress the same way.
    $base = Capability::usingPolicyForContextTarget(
        name: 'orders.view-target',
        ability: 'view',
        resolveTarget: fn (ActionContext $context): ResolvedOrder => new ResolvedOrder(1002),
    );

    $builders = [
        'executionTarget' => fn (Capability $c): Capability => $c->executionTarget(acceptTestSnapshot('context-target-snapshot')),
        'executeUsing' => fn (Capability $c): Capability => $c->executeUsing(fn (): string => 'viewed'),
        'configurationVersion' => fn (Capability $c): Capability => $c->configurationVersion('deploy-sha'),
        'requiresConfirmation' => fn (Capability $c): Capability => $c->requiresConfirmation(fn (): array => ['k' => 'v']),
    ];

    foreach ($builders as $name => $apply) {
        expect($apply($base)->targetSource)
            ->toBe(TargetSource::Context, "{$name}() dropped the target source");
    }

    // And through a chain, since a single-step check would miss a later reset.
    $chained = $base
        ->executionTarget(acceptTestSnapshot('context-target-snapshot'))
        ->configurationVersion('deploy-sha')
        ->executeUsing(fn (): string => 'viewed');

    expect($chained->targetSource)->toBe(TargetSource::Context);
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('evidence'));
    $schema->dropIfExists(verdictTable('derivations'));
});

it('persists the target source to the durable evidence store', function (): void {
    // The in-memory recorder retains the whole DecisionEvidence object, so a test against it proves
    // the field is *set* — not that it survives to a store an auditor can query. ADR 0025's stated
    // purpose is a queryable population, and that claim is only true if the column exists.
    // Dropped first, and dropped again afterwards: this builds the table in the test body, so
    // without both halves the file both inherits and leaves behind state, and which of those bites
    // depends on the random test order.
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('evidence'));
    $schema->dropIfExists(verdictTable('derivations'));

    (require __DIR__.'/../../database/migrations/create_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_provenance_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_invocation_id_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_tool_kind_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_configuration_fingerprint_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_target_source_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_tool_description_fingerprints_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_record_identity_to_verdict_evidence_table.php.stub')->up();

    $recorder = new DatabaseEvidenceRecorder(
        connection: app(DatabaseManager::class)->connection(),
        table: verdictTable('evidence'),
    );

    $this->app->instance(EvidenceRecorder::class, $recorder);
    $this->app->forgetScopedInstances();

    app(VerdictManager::class)->capability(
        Capability::usingPolicyForContextTarget(
            name: 'orders.view-target',
            ability: 'view',
            resolveTarget: fn (ActionContext $context): ResolvedOrder => new ResolvedOrder(1002),
        )->executionTarget(acceptTestSnapshot('context-target-snapshot'))
            ->executeUsing(fn (): string => 'viewed'),
    );

    app(VerdictManager::class)->runBound(targetSourceEnvelope(1001, 1002));

    // The query the field exists to support: find decisions by resolution path.
    $rows = DB::table(verdictTable('evidence'))->where('target_source', TargetSource::Context->value)->get();

    expect($rows)->not->toBeEmpty();
});
