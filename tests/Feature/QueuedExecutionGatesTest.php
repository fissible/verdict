<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\ProvenanceLedgerStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

final class QueuedGateState
{
    public int $handled = 0;

    public int $authorizations = 0;

    public int $executions = 0;

    public bool $denyExecution = false;

    public bool $atMostOnce = false;

    public bool $rateLimited = false;

    public bool $releasesContext = false;
}

final readonly class QueuedGateTarget
{
    public function __construct(public int $id) {}
}

final class QueuedGateDefinition implements Tool
{
    public function description(): Stringable|string
    {
        return 'Do the queued test action.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'The Verdict-bound tool should handle this action.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class QueuedGateTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Do the queued test action.';
    }

    public static function register(): void
    {
        $state = app(QueuedGateState::class);
        $verdict = app(VerdictManager::class);

        $capability = Capability::usingPolicy(
            name: 'operations.queued',
            ability: 'operate',
            resolveTarget: fn (ActionEnvelope $envelope): QueuedGateTarget => new QueuedGateTarget(
                (int) $envelope->proposal->arguments['operation_id'],
            ),
        )->executionTarget(acceptTestSnapshot('queued-gate-target'))->executeUsing(
            function (AuthorizedAction $action) use ($state): string {
                $state->executions++;

                return 'executed';
            },
        );

        if ($state->atMostOnce) {
            $capability = $capability->atMostOnce(ExecutionClaimPolicy::named(
                'queued-operation',
                fn (ActionEnvelope $envelope, QueuedGateTarget $target): array => ['operation_id' => $target->id],
            ));
        }

        if ($state->rateLimited) {
            $capability = $capability->rateLimit(RateLimitPolicy::fixedWindow(
                name: 'queued-operations-per-customer',
                limit: 1,
                windowSeconds: 60,
                keyUsing: fn (ActionEnvelope $envelope): array => ['customer' => $envelope->context->actor],
            ));
        }

        $registry = app(CapabilityRegistry::class);

        if (! $registry->has('operations.queued')) {
            $verdict->capability($capability);
        }
    }

    public function handle(Request $request): Stringable|string
    {
        $state = app(QueuedGateState::class);
        $state->handled++;
        $verdict = app(VerdictManager::class);

        if ($state->releasesContext) {
            $source = Source::application('queued-customer-profile');
            $destination = Destination::connection('queued-provider', 'local-machine');

            $verdict->releasePolicy(
                ReleasePolicy::between($source, $destination)
                    ->allow(DataClass::PII)
                    ->whenTrustIs(Trust::Trusted),
            );

            $verdict->release(['first_name' => 'Avery', 'ssn' => '111-22-3333'])
                ->source($source)
                ->trust(Trust::Trusted)
                ->classify(DataClass::PII)
                ->only(['first_name'])
                ->to($destination);
        }

        return $verdict->bound(
            new QueuedGateDefinition,
            'operations.queued',
            new ActionContext('customer-72'),
        )->handle($request);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class QueuedGateAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Call the tool.';
    }

    public function tools(): array
    {
        QueuedGateTool::register();

        return [new QueuedGateTool];
    }

    public function maxSteps(): int
    {
        return 3;
    }

    // Register the fake gateway for the worker's provider but do not let Laravel AI short-circuit
    // queue() into FakePendingDispatch. The database queue must receive the real InvokeAgent job.
    public static function isFaked(): bool
    {
        return false;
    }
}

/** @return list<string> */
function queuedGateTables(): array
{
    return [
        'jobs',
        'verdict_provenance_derivations',
        'verdict_capability_configurations',
        'verdict_execution_claims',
        'verdict_rate_limit_buckets',
        'verdict_approval_receipts',
        'verdict_evidence',
        'failed_jobs',
        'job_batches',
    ];
}

function resetQueuedGateTables(): void
{
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach (queuedGateTables() as $table) {
        $schema->dropIfExists($table);
    }
}

beforeEach(function (): void {
    resetQueuedGateTables();

    (require __DIR__.'/../../vendor/orchestra/testbench-core/laravel/migrations/0001_01_01_000002_testbench_create_jobs_table.php')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_approval_receipts_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_provenance_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_invocation_id_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_provenance_derivations_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_tool_kind_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_configuration_fingerprint_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_rate_limit_buckets_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_execution_claims_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_capability_configurations_table.php.stub')->up();

    $state = new QueuedGateState;
    $this->app->instance(QueuedGateState::class, $state);
    $this->app->instance(CapabilityAuthorizer::class, new class($state) implements CapabilityAuthorizer
    {
        public function __construct(private QueuedGateState $state) {}

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            $this->state->authorizations++;

            return $this->state->denyExecution && $this->state->authorizations === 2
                ? Decision::deny('Authority changed before execution.')
                : Decision::permit();
        }
    });

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', config('database.default'));
    config()->set('queue.connections.database.table', 'jobs');
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    config()->set('verdict.rate_limits.store', DatabaseRateLimitStore::class);
    config()->set('verdict.execution_claims.store', DatabaseExecutionClaimStore::class);
    config()->set('verdict.capability_configurations.store', DatabaseCapabilityConfigurationStore::class);

    foreach ([
        CapabilityConfigurationStore::class,
        CapabilityRegistry::class,
        ApprovalReceiptStore::class,
        EvidenceRecorder::class,
        EvidenceWriter::class,
        ProvenanceLedgerStore::class,
        RateLimitStore::class,
        ExecutionClaimStore::class,
    ] as $binding) {
        $this->app->forgetInstance($binding);
    }

    $this->app->forgetScopedInstances();
});

afterEach(function (): void {
    resetQueuedGateTables();
});

/** @param array<int, ToolCall|string> $responses */
function queuedGateAgent(array $responses): QueuedGateAgent
{
    QueuedGateAgent::fake($responses);

    return new QueuedGateAgent;
}

function runQueuedGate(QueuedGateAgent $agent): void
{
    $response = $agent->queue('do it');
    unset($response);

    expect(app('db')->table('jobs')->count())->toBe(1);

    test()->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])
        ->assertSuccessful();

    expect(app('db')->table('jobs')->count())->toBe(0)
        ->and(app(QueuedGateState::class)->handled)->toBeGreaterThan(0);
}

it('dispatches and handles a queued Laravel AI agent job through Verdict in worker-visible container state', function (): void {
    runQueuedGate(queuedGateAgent([new ToolCall('queued-test', 'QueuedGateTool', ['operation_id' => 1001]), 'done']));

    expect(app('db')->table('jobs')->count())->toBe(0)
        ->and(app(QueuedGateState::class)->authorizations)->toBe(2)
        ->and(app(QueuedGateState::class)->handled)->toBe(1)
        ->and(app(QueuedGateState::class)->executions)->toBe(1)
        ->and(app(EvidenceRecorder::class))->toBeInstanceOf(DatabaseEvidenceRecorder::class)
        ->and(app(RateLimitStore::class))->toBeInstanceOf(DatabaseRateLimitStore::class)
        ->and(app(ExecutionClaimStore::class))->toBeInstanceOf(DatabaseExecutionClaimStore::class)
        ->and(app('db')->table('verdict_evidence')->where('record_type', 'decision')->count())->toBeGreaterThan(0)
        ->and(app('db')->table('verdict_capability_configurations')->count())->toBe(1);
});

it('denies a queued execution when authorization changes after the proposal check', function (): void {
    $state = app(QueuedGateState::class);
    $state->denyExecution = true;

    runQueuedGate(queuedGateAgent([new ToolCall('queued-denied', 'QueuedGateTool', ['operation_id' => 1001]), 'done']));

    expect($state->authorizations)->toBe(2)
        ->and($state->executions)->toBe(0)
        ->and(app('db')->table('verdict_evidence')->where('stage', 'execution')->where('disposition', 'deny')->count())->toBe(1);
});

it('enforces execution claims and semantic rate limits across queued jobs', function (): void {
    $state = app(QueuedGateState::class);
    $state->atMostOnce = true;

    runQueuedGate(queuedGateAgent([new ToolCall('queued-claim-a', 'QueuedGateTool', ['operation_id' => 1001]), 'done']));
    runQueuedGate(queuedGateAgent([new ToolCall('queued-claim-b', 'QueuedGateTool', ['operation_id' => 1001]), 'done']));

    expect($state->executions)->toBe(1)
        ->and(app('db')->table('verdict_execution_claims')->count())->toBe(1)
        ->and(app('db')->table('verdict_execution_claims')->value('status'))->toBe('completed')
        ->and(app('db')->table('verdict_evidence')->where('stage', 'execution_claim')->count())->toBe(3);
});

it('enforces semantic rate limits across queued jobs', function (): void {
    $state = app(QueuedGateState::class);
    $state->rateLimited = true;

    runQueuedGate(queuedGateAgent([new ToolCall('queued-limit-a', 'QueuedGateTool', ['operation_id' => 1002]), 'done']));
    runQueuedGate(queuedGateAgent([new ToolCall('queued-limit-b', 'QueuedGateTool', ['operation_id' => 1003]), 'done']));

    expect($state->executions)->toBe(1)
        ->and(app('db')->table('verdict_rate_limit_buckets')->count())->toBe(1)
        ->and(app('db')->table('verdict_rate_limit_buckets')->value('attempts'))->toBe(1)
        ->and(app('db')->table('verdict_evidence')->where('stage', 'rate_limit')->where('disposition', 'throttle')->count())->toBe(1);
});

it('records a context release performed by a queued worker', function (): void {
    $state = app(QueuedGateState::class);
    $state->releasesContext = true;

    runQueuedGate(queuedGateAgent([new ToolCall('queued-release', 'QueuedGateTool', ['operation_id' => 1001]), 'done']));

    expect(app('db')->table('verdict_evidence')->where('record_type', 'context_release')->count())->toBe(1)
        ->and(app('db')->table('verdict_evidence')->where('record_type', 'context_release')->value('disposition'))->toBe('permit')
        ->and(app('db')->table('verdict_evidence')->where('record_type', 'context_release')->value('released_path_fingerprints'))->not->toContain('first_name');
});
