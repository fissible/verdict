<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Schema;
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
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolCall as StreamToolCall;
use Laravel\Ai\Tools\Request;

final readonly class StreamedApprovalOrder
{
    public function __construct(public int $id) {}
}

final class StreamedApprovalCancelTool implements Tool
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

final readonly class StreamedApprovalCustomer
{
    public function __construct(public int $id) {}
}

final class StreamedApprovalAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function __construct(private readonly Tool $tool) {}

    public function instructions(): Stringable|string
    {
        return 'Cancel orders when asked.';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [$this->tool];
    }

    public function maxSteps(): int
    {
        return 3;
    }
}

function streamedApprovalBind(int &$executions): Tool
{
    app()->bind(CapabilityAuthorizer::class, fn (): CapabilityAuthorizer => new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('test');
        }
    });

    app(VerdictManager::class)->capability(
        Capability::usingPolicy(
            name: 'orders.cancel-streamed-approval',
            ability: 'update',
            resolveTarget: fn (ActionEnvelope $e): StreamedApprovalOrder => new StreamedApprovalOrder(
                (int) $e->proposal->arguments['order_id'],
            ),
        )
            // Required: VerdictManager::requestConfirmation() returns null without an execution-target
            // policy, so the tool never asks Laravel AI to pause. See the regression test below.
            ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                name: 'streamed-approval-target',
                identityUsing: fn (ActionEnvelope $e, StreamedApprovalOrder $t): array => ['id' => $t->id],
            ))
            ->requiresConfirmation(fn (ActionEnvelope $e, StreamedApprovalOrder $t): array => ['order_id' => $t->id])
            ->executeUsing(function (AuthorizedAction $a) use (&$executions): string {
                $executions++;

                return 'Order cancelled.';
            }),
    );

    return app(VerdictManager::class)->bound(
        new StreamedApprovalCancelTool,
        'orders.cancel-streamed-approval',
        new ActionContext('customer-7'),
    );
}

/**
 * The round trip the compatibility matrix claims and nothing exercised: a confirmation-gated capability
 * pauses a *streamed* agent run, and resuming with an approval executes it. Its absence is why a wrong
 * claim about this path survived to a merged documentation change — the suite's approval coverage mocks
 * the agent, and its streaming coverage never touches approvals, so neither saw the intersection.
 */
it('pauses a streamed run for confirmation and resumes without raising', function (): void {
    // Laravel AI resumes a pending tool call from conversation history, so the agent must be
    // conversational and the shipped store's tables must exist.
    if (! Schema::hasTable('agent_conversations')) {
        (require dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php')->up();
    }

    $executions = 0;
    $tool = streamedApprovalBind($executions);

    StreamedApprovalAgent::fake([
        new ToolCall('streamed-approval-call', 'StreamedApprovalCancelTool', ['order_id' => 1001]),
        'done',
    ]);

    $agent = (new StreamedApprovalAgent($tool))->forParticipant(new StreamedApprovalCustomer(7));

    $paused = null;
    $turn1 = $agent->stream('Please cancel order 1001.');
    $turn1->then(function ($response) use (&$paused): void {
        $paused = $response;
    });
    iterator_to_array($turn1);

    expect($paused?->hasPendingApprovals())->toBeTrue('A confirmation-gated capability must pause a streamed run.')
        ->and($executions)->toBe(0, 'The executor must not run before approval.');

    // Resuming is exercised here for the failure mode it used to have — ApprovalNotResumableException,
    // which is what a non-conversational agent raises — but this test deliberately does not assert that
    // the executor runs afterwards. Under Agent::fake() it does not, and whether that is the product or
    // the fake is unresolved: a fake supplies provider output, and a resume replays a pending call from
    // conversation history rather than asking the model again. Asserting completion here would pin
    // whichever answer the fake happens to give. The completion half is tracked in #218 and needs a live
    // provider; see docs/architecture.md's compatibility-matrix note for the boundary.
    $resumed = null;
    $turn2 = $agent->stream(AiDecision::approveAll());
    $turn2->then(function ($response) use (&$resumed): void {
        $resumed = $response;
    });
    iterator_to_array($turn2);

    expect($resumed)->not->toBeNull('Resuming an approved streamed run must not raise.');
});

/**
 * The trap that made a confirmation gate look wired and silently never pause, and which cost a wrong
 * documentation change before it was found: `VerdictManager::requestConfirmation()` returns null — so
 * `shouldRequestApproval()` returns null and Laravel AI has nothing to pause on — when the capability
 * declares `requiresConfirmation()` but no execution-target policy.
 */
it('does not request approval when a confirmation-gated capability has no execution-target policy', function (): void {
    app()->bind(CapabilityAuthorizer::class, fn (): CapabilityAuthorizer => new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('test');
        }
    });

    app(VerdictManager::class)->capability(
        Capability::usingPolicy(
            name: 'orders.cancel-no-target',
            ability: 'update',
            resolveTarget: fn (ActionEnvelope $e): StreamedApprovalOrder => new StreamedApprovalOrder(
                (int) $e->proposal->arguments['order_id'],
            ),
        )
            ->requiresConfirmation(fn (ActionEnvelope $e, StreamedApprovalOrder $t): array => ['order_id' => $t->id])
            ->executeUsing(fn (AuthorizedAction $a): string => 'Order cancelled.'),
    );

    $tool = app(VerdictManager::class)->bound(
        new StreamedApprovalCancelTool,
        'orders.cancel-no-target',
        new ActionContext('customer-7'),
    );

    expect($tool->shouldRequestApproval(new Request(['order_id' => 1001], 'no-target-call')))->toBeNull(
        'This documents current behaviour rather than endorsing it: a capability that asks for confirmation '
        .'and declares no execution target never pauses the agent, and nothing warns. If this starts '
        .'returning an Approval, that is an improvement — update this test and verdict:validate together.',
    );
});

final class StreamedApprovalCompletionAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function __construct(private readonly Tool $tool) {}

    public function instructions(): Stringable|string
    {
        return 'Cancel orders when asked.';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [$this->tool];
    }

    /** @return array<int, object> */
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
 * A gateway, not `Agent::fake()`. The fake is structurally incapable of answering this:
 * `ResumesToolApprovals::resumableApprovalFor()` returns null when `Ai::hasFakeGatewayFor()` is true, so a
 * faked agent never resumes tools at all. A `StepTextGateway` controls only the provider's output while
 * Laravel AI's real provider, response, stream, and resume pipeline run — the same substitution
 * `StreamedExecutionGatesTest` uses.
 */
final class StreamedApprovalCompletionGateway implements StepTextGateway
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
        throw new LogicException('This gateway only supports stream().');
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     * @return Generator<int, StreamEvent, mixed, StepResponse|null>
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
        if ($stepContext->stepNumber === 0) {
            yield (new StreamStart('approval-completion', 'openai', $model, time()))->withInvocationId($invocationId);
            yield (new StreamToolCall('event-'.$this->toolCall->id, $this->toolCall, time()))->withInvocationId($invocationId);

            return new StepResponse(
                text: '',
                toolCalls: [$this->toolCall],
                finishReason: FinishReason::ToolCalls,
                usage: new Usage,
                meta: new Meta('openai', $model),
            );
        }

        yield (new StreamStart('approval-completion', 'openai', $model, time()))->withInvocationId($invocationId);

        return new StepResponse(
            text: 'Order cancelled.',
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new Usage,
            meta: new Meta('openai', $model),
        );
    }
}

/**
 * The completion half of #218, and the two requirements it surfaced — neither of which is obvious, and
 * both of which produce silent non-execution that looks exactly like a broken feature:
 *
 * 1. The receipt must be approved in **Verdict**, through the application's own authenticated flow.
 *    `Decision::approveAll()` is the agent framework's approval, not a human's.
 * 2. The resume must carry a **specific** tool-call decision. `approveAll()` yields a wildcard `'*'`, and
 *    `ApprovalExecutionContext::push()` deliberately skips it — a blanket approval from the agent loop
 *    must not authorize a specific consequential action.
 */
it('executes a confirmation-gated capability when an approved receipt is resumed with a specific decision', function (): void {
    if (! Schema::hasTable('agent_conversations')) {
        (require dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php')->up();
    }

    $executions = 0;
    $tool = streamedApprovalBind($executions);

    Ai::textProvider('openai')->useTextGateway(new StreamedApprovalCompletionGateway(
        new ToolCall('completion-call', 'StreamedApprovalCancelTool', ['order_id' => 1001]),
    ));

    $agent = (new StreamedApprovalCompletionAgent($tool))->forParticipant(new StreamedApprovalCustomer(7));

    $paused = null;
    $turn1 = $agent->stream('Please cancel order 1001.');
    $turn1->then(function ($response) use (&$paused): void {
        $paused = $response;
    });
    iterator_to_array($turn1);

    expect($paused?->hasPendingApprovals())->toBeTrue()
        ->and($executions)->toBe(0, 'The executor must not run before approval.');

    // Requirement 1: a human approves in Verdict.
    $approvals = app(VerdictManager::class)->approvals();
    $decisions = [];

    foreach ($paused->pendingApprovals as $pending) {
        $challenge = $approvals->challengeForToolCall($pending->id);
        expect($challenge)->not->toBeNull('A confirmation-gated capability must issue a challenge.');
        $approvals->approve($challenge->receiptId, $challenge->toolCallId, 'test-human');
        // Requirement 2: a specific decision, never approveAll()'s wildcard.
        $decisions[$pending->id] = AiDecision::approve();
    }

    $turn2 = $agent->stream(Decisions::from($decisions));
    iterator_to_array($turn2);

    expect($executions)->toBe(1, 'An approved, specifically-decided resume must execute the capability once.');
});
