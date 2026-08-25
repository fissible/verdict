<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
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
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decision as AiDecision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Tools\Request;

final class QueuedApprovalState
{
    public int $executions = 0;
}

final readonly class QueuedApprovalOrder
{
    public function __construct(public int $id) {}
}

final readonly class QueuedApprovalCustomer
{
    public function __construct(public int $id) {}
}

final class QueuedApprovalCancelTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Cancel an order by id.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'The Verdict-bound tool handles this.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

/**
 * Build the bound tool, registering the capability if this container has not seen it yet.
 *
 * This runs inside `tools()` rather than being handed to the agent's constructor: `InvokeAgent`
 * serializes the agent into the job payload, and a `BoundTool` closes over `VerdictManager` and
 * the capability's executor closures. An agent holding one as a property cannot be queued.
 */
function queuedApprovalBoundTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('orders.cancel-queued-approval')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.cancel-queued-approval',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): QueuedApprovalOrder => new QueuedApprovalOrder(
                    (int) $e->proposal->arguments['order_id'],
                ),
            )
                // Required: VerdictManager::requestConfirmation() returns null without an execution-target
                // policy, so the tool never asks Laravel AI to pause and the queued run never gets as far
                // as needing a resume. Identity is the order id rather than the suite's default
                // spl_object_id snapshot, which is only stable within one process's object graph.
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'queued-approval-target',
                    identityUsing: fn (ActionEnvelope $e, QueuedApprovalOrder $t): array => ['id' => $t->id],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, QueuedApprovalOrder $t): array => ['order_id' => $t->id])
                ->executeUsing(function (AuthorizedAction $a): string {
                    app(QueuedApprovalState::class)->executions++;

                    return 'Order cancelled.';
                }),
        );
    }

    return $verdict->bound(
        new QueuedApprovalCancelTool,
        'orders.cancel-queued-approval',
        new ActionContext('customer-7'),
    );
}

final class QueuedApprovalAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'Cancel orders when asked.';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [queuedApprovalBoundTool()];
    }

    /**
     * Required: `VerdictApprovalMiddleware` is not auto-registered. Without it
     * `ApprovalExecutionContext::allows()` is false for every tool call on the resume and an approved
     * receipt fails proposal-validation with `invalid_state`.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [app(VerdictApprovalMiddleware::class)];
    }

    public function provider(): string
    {
        return 'openai';
    }

    public function maxSteps(): int
    {
        return 3;
    }
}

/**
 * A gateway, not `Agent::fake()`. `ResumesToolApprovals::resumableApprovalFor()` returns null when
 * `Ai::hasFakeGatewayFor()` is true, so a faked agent never resumes tools at all and would report
 * non-execution for a reason that has nothing to do with Verdict.
 *
 * It implements `generateTextStep` because the queued path is `InvokeAgent::handle()` → `prompt()`;
 * the streamed equivalent only ever needs `generateStreamStep`. It decides what to return from the
 * incoming messages rather than from a step counter, because each dispatch is its own generation
 * loop starting at step 0: on the resume the pending call has already been replayed from
 * conversation history, and re-emitting it would ask for the same action a second time.
 */
final class QueuedApprovalGateway implements StepTextGateway
{
    public function __construct(private readonly ToolCall $toolCall) {}

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        if ($this->alreadyCalledTheTool($messages)) {
            return new StepResponse(
                text: 'Order cancelled.',
                toolCalls: [],
                finishReason: FinishReason::Stop,
                usage: new Usage,
                meta: new Meta('openai', $model),
            );
        }

        return new StepResponse(
            text: '',
            toolCalls: [$this->toolCall],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage,
            meta: new Meta('openai', $model),
        );
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     * @return Generator<int, mixed, mixed, StepResponse|null>
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        throw new LogicException('This gateway only supports prompt().');
    }

    /** @param  Message[]  $messages */
    private function alreadyCalledTheTool(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }
}

/** @return list<string> */
function queuedApprovalTables(): array
{
    return [
        'jobs',
        'failed_jobs',
        'job_batches',
        'agent_conversations',
        'agent_conversation_messages',
        verdictTable('capability_configurations'),
        verdictTable('approvals'),
        verdictTable('evidence'),
    ];
}

function resetQueuedApprovalTables(): void
{
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach (queuedApprovalTables() as $table) {
        $schema->dropIfExists($table);
    }
}

beforeEach(function (): void {
    resetQueuedApprovalTables();

    (require __DIR__.'/../../vendor/orchestra/testbench-core/laravel/migrations/0001_01_01_000002_testbench_create_jobs_table.php')->up();
    (require __DIR__.'/../../vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_approval_receipts_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_proposal_provenance_to_verdict_approval_receipts_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_approval_context_to_verdict_approval_receipts_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_provenance_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_invocation_id_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_tool_kind_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_configuration_fingerprint_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_target_source_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_tool_description_fingerprints_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_record_identity_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_intent_id_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_capability_configurations_table.php.stub')->up();

    $this->app->instance(QueuedApprovalState::class, new QueuedApprovalState);
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('test');
        }
    });

    config()->set('cache.default', 'array');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', config('database.default'));
    config()->set('queue.connections.database.table', 'jobs');
    // The conversation store generates a title through the same provider, which would consume this
    // test's gateway for a turn nothing asserts on.
    config()->set('ai.conversations.generate_title', false);
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
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

    Ai::textProvider('openai')->useTextGateway(new QueuedApprovalGateway(
        new ToolCall('queued-approval-call', 'QueuedApprovalCancelTool', ['order_id' => 1001]),
    ));
});

afterEach(function (): void {
    resetQueuedApprovalTables();
});

function queuedApprovalWork(): void
{
    expect(app('db')->table('jobs')->count())->toBe(1, 'Agent::queue() must write a real InvokeAgent job.');

    // --force prevents a maintenance-mode test application from turning the worker invocation into a
    // successful no-op, which would look like coverage while polling nothing.
    test()->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default', '--force' => true])
        ->assertSuccessful();

    $failure = app('db')->table('failed_jobs')->first();

    expect($failure?->exception)->toBeNull('The queued job must not fail: '.($failure->exception ?? ''));
    expect(app('db')->table('jobs')->count())->toBe(0, 'The worker must consume the job.');
}

/** Dispatch the opening turn and run it, returning the tool-call id the worker paused on. */
function queuedApprovalPause(): string
{
    (new QueuedApprovalAgent)
        ->forParticipant(new QueuedApprovalCustomer(7))
        ->queue('Please cancel order 1001.');

    queuedApprovalWork();

    $pending = app('db')->table(verdictTable('approvals'))->where('status', 'pending')->get();

    expect($pending)->toHaveCount(1, 'A confirmation-gated capability must leave one pending receipt behind.')
        ->and(app(QueuedApprovalState::class)->executions)->toBe(0, 'The executor must not run before approval.');

    return $pending->first()->tool_call_id;
}

/** Dispatch the resuming turn with the given decisions and run it. */
function queuedApprovalResume(Decisions $decisions): void
{
    // The paused turn lives in the durable conversation store, not in the first job's response; a
    // resume reconstructs the pending call from conversation history.
    (new QueuedApprovalAgent)
        ->continueLastConversation(new QueuedApprovalCustomer(7))
        ->queue($decisions);

    queuedApprovalWork();
}

/**
 * Assert the resume actually reached Verdict.
 *
 * Without this, every "it did not execute" below would also pass for a resume that never ran the tool
 * at all — the exact false negative a faked gateway produces. The approval stage is only reachable
 * from a resume carrying decisions: the opening turn leaves one proposal row and nothing else.
 */
function expectQueuedApprovalWasEvaluatedOnResume(): void
{
    expect(app('db')->table(verdictTable('evidence'))->where('stage', 'approval')->count())
        ->toBeGreaterThan(0, 'The resume must have re-invoked the tool and asked Verdict to evaluate the receipt.');
}

/**
 * The last unverified cell of the execution-mode compatibility matrix: a confirmation-gated capability
 * pauses a *queued* run, and a second dispatch carrying an approved receipt and a specific decision
 * executes it exactly once. The claim this replaces said the blocker was that `InvokeAgent` does not
 * retain the first job's response — but a resume never reads it, so the gap was only coverage.
 */
it('executes a queued confirmation-gated capability once when an approved receipt is resumed with a specific decision', function (): void {
    $toolCallId = queuedApprovalPause();

    // Requirement 1: a human approves in Verdict, through the application's own authenticated flow.
    // Decision::approve() below is the agent framework's approval, and cannot stand in for this.
    $approvals = app(VerdictManager::class)->approvals();
    $challenge = $approvals->challengeForToolCall($toolCallId);

    expect($challenge)->not->toBeNull('A pending receipt must yield a challenge for the approver to read.');

    $approvals->approve($challenge->receiptId, $challenge->toolCallId, 'test-human');

    // Requirement 2: a specific tool-call decision. approveAll()'s wildcard is deliberately skipped.
    queuedApprovalResume(Decisions::from([$toolCallId => AiDecision::approve()]));

    expect(app(QueuedApprovalState::class)->executions)->toBe(1, 'An approved, specifically-decided queued resume must execute the capability once.')
        ->and(app('db')->table(verdictTable('evidence'))->where('stage', 'execution')->where('disposition', 'permit')->count())
        ->toBe(1, 'The worker must leave durable execution evidence behind.');
});

/**
 * `Decision::approveAll()` yields a wildcard `'*'` that `ApprovalExecutionContext::push()` deliberately
 * skips: a blanket approval from the agent loop must not authorize a specific consequential action.
 * Asserted here because it is otherwise indistinguishable from a broken feature — the capability
 * silently does not run.
 */
it('does not execute a queued resume that carries only a wildcard approval', function (): void {
    $toolCallId = queuedApprovalPause();

    $approvals = app(VerdictManager::class)->approvals();
    $challenge = $approvals->challengeForToolCall($toolCallId);
    $approvals->approve($challenge->receiptId, $challenge->toolCallId, 'test-human');

    queuedApprovalResume(AiDecision::approveAll());

    expectQueuedApprovalWasEvaluatedOnResume();

    expect(app(QueuedApprovalState::class)->executions)->toBe(0, 'A wildcard approval must not authorize a specific consequential action.');
});

/**
 * The receipt must be approved in Verdict. Approving the agent framework's pending call is not
 * approving Verdict's receipt, and the difference is the whole point of the gate.
 */
it('does not execute a queued resume whose receipt was never approved in Verdict', function (): void {
    $toolCallId = queuedApprovalPause();

    queuedApprovalResume(Decisions::from([$toolCallId => AiDecision::approve()]));

    expectQueuedApprovalWasEvaluatedOnResume();

    expect(app(QueuedApprovalState::class)->executions)->toBe(0, 'An unapproved receipt must not execute, however the agent loop decided.');
});
