<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\LaravelAi\BoundTool;
use Fissible\Verdict\LaravelAi\InvocationContext;
use Fissible\Verdict\LaravelAi\VerdictProvenanceMiddleware;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolCall as StreamToolCall;
use Laravel\Ai\Tools\Request;

final readonly class StreamedGateTarget
{
    public function __construct(public int $id) {}
}

final readonly class StreamedGateClock implements Clock
{
    public function __construct(private DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

final class StreamedGateDefinition implements Tool
{
    public function description(): Stringable|string
    {
        return 'Perform the protected operation.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'The bound executor should handle this operation.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

/**
 * Test-only provider boundary for the one response shape FakeTextGateway cannot express: two tool
 * calls returned from one streamed generation step. Laravel AI's provider, response, stream, and
 * tool-execution loop remain real.
 */
final class StreamedGateTwoToolCallsGateway implements StepTextGateway
{
    /** @var list<int> */
    public array $observedSteps = [];

    /** @param list<ToolCall> $toolCalls */
    public function __construct(private readonly array $toolCalls) {}

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
        throw new LogicException('This test gateway only supports Agent::stream().');
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
        $this->observedSteps[] = $stepContext->stepNumber;

        if ($stepContext->stepNumber === 0) {
            yield (new StreamStart('two-tool-calls', 'openai', $model, time()))->withInvocationId($invocationId);

            foreach ($this->toolCalls as $toolCall) {
                yield (new StreamToolCall('event-'.$toolCall->id, $toolCall, time()))->withInvocationId($invocationId);
            }

            return new StepResponse(
                text: '',
                toolCalls: $this->toolCalls,
                finishReason: FinishReason::ToolCalls,
                usage: new Usage,
                meta: new Meta('openai', $model),
            );
        }

        if ($stepContext->stepNumber === 1) {
            return new StepResponse(
                text: 'completed',
                toolCalls: [],
                finishReason: FinishReason::Stop,
                usage: new Usage,
                meta: new Meta('openai', $model),
            );
        }

        throw new LogicException('The test gateway only defines two generation steps.');
    }
}

final class StreamedGateAgent implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    public function __construct(private readonly Tool $tool) {}

    public function instructions(): Stringable|string
    {
        return 'Call the protected operation.';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [$this->tool];
    }

    public function maxSteps(): int
    {
        return 2;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new VerdictProvenanceMiddleware(
            provenance: app(ProvenanceLedger::class),
            trust: Trust::Untrusted,
            dataClass: DataClass::Internal,
        )];
    }
}

/** @param callable(AuthorizedAction): string $executor */
function streamedGateCapability(
    string $name,
    callable $executor,
    ?ExecutionClaimPolicy $claim = null,
    ?RateLimitPolicy $rateLimit = null,
): Capability {
    $capability = Capability::usingPolicy(
        name: $name,
        ability: 'operate',
        resolveTarget: fn (ActionEnvelope $envelope): StreamedGateTarget => new StreamedGateTarget(
            (int) $envelope->proposal->arguments['operation_id'],
        ),
    )->executionTarget(acceptTestSnapshot('streamed-gate-target'))->executeUsing($executor);

    if ($claim !== null) {
        $capability = $capability->atMostOnce($claim);
    }

    return $rateLimit === null ? $capability : $capability->rateLimit($rateLimit);
}

/** @param array<int, ToolCall|string> $responses */
function streamedGateAgent(Tool $tool, array $responses): StreamedGateAgent
{
    StreamedGateAgent::fake($responses);

    return new StreamedGateAgent($tool);
}

function streamedGateAgentWithTwoToolCalls(Tool $tool, StreamedGateTwoToolCallsGateway $gateway): StreamedGateAgent
{
    Ai::textProvider('openai')->useTextGateway($gateway);

    return new StreamedGateAgent($tool);
}

function streamedGateEvidence(): InMemoryEvidenceRecorder
{
    $evidence = app(EvidenceRecorder::class);

    if (! $evidence instanceof InMemoryEvidenceRecorder) {
        throw new RuntimeException('These cases require the in-memory evidence recorder bound by the test suite.');
    }

    return $evidence;
}

function assertStreamedGateEvidenceIsCorrelated(string $invocationId, int $offset = 0): void
{
    $evidence = streamedGateEvidence();

    expect(collect($evidence->all())->slice($offset)->pluck('invocationId')->unique()->values()->all())
        ->toBe([$invocationId])
        ->and(app(InvocationContext::class)->current())->toBeNull();
}

/**
 * @param  array<string, mixed>  $arguments
 * @param  list<mixed>  $results
 */
function captureStreamedToolResults(BoundTool $tool, array $arguments, array &$results): void
{
    // ToolInvoked is Laravel AI's in-process lifecycle event, not an SSE event. Its result becomes
    // the next-step ToolResultMessage after the gateway stream returns its StepResponse.
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use ($tool, $arguments, &$results): void {
        if ($event->tool === $tool && $event->arguments === $arguments) {
            $results[] = $event->result;
        }
    });
}

/**
 * @param  list<mixed>  $results
 */
function assertStreamedDeniedToolResult(array $results, string $capability, string $decision): void
{
    expect($results)->toHaveCount(1)
        ->and($results[0])->toBeString();

    $payload = json_decode($results[0], true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toBe([
        'status' => 'not_executed',
        'capability' => $capability,
        'decision' => $decision,
        'message' => 'This action was not authorized.',
    ]);
}

it('runs proposal and execution authorization during lazy Agent streaming and denies before the executor', function (): void {
    $authorizationCalls = 0;
    $executorCalls = 0;
    $this->app->instance(CapabilityAuthorizer::class, new class($authorizationCalls) implements CapabilityAuthorizer
    {
        public function __construct(private int &$authorizationCalls) {}

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            $this->authorizationCalls++;

            return $this->authorizationCalls === 1
                ? Decision::permit()
                : Decision::deny('Authority changed before execution.');
        }
    });

    $verdict = app(VerdictManager::class);
    $verdict->capability(streamedGateCapability(
        'operations.stream-authorize',
        function (AuthorizedAction $action) use (&$executorCalls): string {
            $executorCalls++;

            return 'executed';
        },
    ));

    $tool = $verdict->bound(new StreamedGateDefinition, 'operations.stream-authorize', new ActionContext('customer-72'));
    $agent = streamedGateAgent(
        $tool,
        [new ToolCall('stream-authorization', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done'],
    );
    $toolResults = [];
    captureStreamedToolResults($tool, ['operation_id' => 1001], $toolResults);

    $response = $agent->stream('perform operation');

    expect($authorizationCalls)->toBe(0)
        ->and($executorCalls)->toBe(0)
        ->and($toolResults)->toBe([]);

    iterator_to_array($response);

    expect($authorizationCalls)->toBe(2)
        ->and($executorCalls)->toBe(0);

    $evidence = streamedGateEvidence();

    expect(collect($evidence->all())->pluck('stage')->all())->toBe(['proposal', 'target_refresh', 'execution'])
        ->and(collect($evidence->all())->pluck('disposition')->all())->toBe(['permit', 'permit', 'deny']);

    assertStreamedDeniedToolResult($toolResults, 'operations.stream-authorize', 'deny');
    assertStreamedGateEvidenceIsCorrelated($response->invocationId);
});

it('prevents a duplicate logical action when it is invoked through separate Agent streams', function (): void {
    $executorCalls = 0;
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
    $verdict = app(VerdictManager::class);
    $verdict->capability(streamedGateCapability(
        'operations.stream-claim',
        function (AuthorizedAction $action) use (&$executorCalls): string {
            $executorCalls++;

            return 'executed';
        },
        ExecutionClaimPolicy::named(
            'stream-operation',
            fn (ActionEnvelope $envelope, StreamedGateTarget $target): array => ['operation_id' => $target->id],
        ),
    ));

    $tool = $verdict->bound(new StreamedGateDefinition, 'operations.stream-claim', new ActionContext('customer-72'));
    $agent = streamedGateAgent(
        $tool,
        [
            new ToolCall('stream-claim-first', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done',
            new ToolCall('stream-claim-duplicate', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done',
        ],
    );
    $toolResults = [];
    captureStreamedToolResults($tool, ['operation_id' => 1001], $toolResults);

    $evidence = streamedGateEvidence();

    $first = $agent->stream('perform operation');
    expect($executorCalls)->toBe(0);
    iterator_to_array($first);
    expect($executorCalls)->toBe(1);

    $recordedBeforeDuplicate = count($evidence->all());
    $toolResultsBeforeDuplicate = count($toolResults);
    assertStreamedGateEvidenceIsCorrelated($first->invocationId);

    $duplicate = $agent->stream('repeat operation');

    // The executor count cannot tell a lazy stream from an eager one here, because the duplicate is
    // denied either way. Recorded evidence can: an eager stream() would have run the duplicate's
    // gates, and recorded their dispositions, before iteration begins.
    expect($evidence->all())->toHaveCount($recordedBeforeDuplicate)
        ->and($executorCalls)->toBe(1)
        ->and($toolResults)->toHaveCount($toolResultsBeforeDuplicate);

    iterator_to_array($duplicate);

    expect(count($evidence->all()))->toBeGreaterThan($recordedBeforeDuplicate)
        ->and($executorCalls)->toBe(1);

    assertStreamedGateEvidenceIsCorrelated($duplicate->invocationId, $recordedBeforeDuplicate);

    expect(collect($evidence->all())->where('stage', 'execution_claim')->pluck('disposition')->all())
        ->toBe(['permit', 'permit', 'deny'])
        ->and($toolResults)->toHaveCount(2)
        ->and($toolResults[0])->toBe('executed');

    assertStreamedDeniedToolResult([$toolResults[1]], 'operations.stream-claim', 'deny');
});

it('consumes and enforces a semantic rate limit through Agent streaming', function (): void {
    $executorCalls = 0;
    $this->app->instance(Clock::class, new StreamedGateClock(
        new DateTimeImmutable('2026-08-01 12:00:15', new DateTimeZone('UTC')),
    ));
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
    $verdict = app(VerdictManager::class);
    $verdict->capability(streamedGateCapability(
        'operations.stream-rate-limit',
        function (AuthorizedAction $action) use (&$executorCalls): string {
            $executorCalls++;

            return 'executed';
        },
        rateLimit: RateLimitPolicy::fixedWindow(
            name: 'one-stream-operation-per-customer',
            limit: 1,
            windowSeconds: 60,
            keyUsing: fn (ActionEnvelope $envelope): array => ['actor' => $envelope->context->actor],
        ),
    ));

    $tool = $verdict->bound(new StreamedGateDefinition, 'operations.stream-rate-limit', new ActionContext('customer-72'));
    $agent = streamedGateAgent(
        $tool,
        [
            new ToolCall('stream-limit-first', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done',
            new ToolCall('stream-limit-second', 'StreamedGateDefinition', ['operation_id' => 1002]), 'done',
        ],
    );
    $toolResults = [];
    captureStreamedToolResults($tool, ['operation_id' => 1002], $toolResults);

    $evidence = streamedGateEvidence();

    $first = $agent->stream('perform first operation');
    expect($executorCalls)->toBe(0);
    iterator_to_array($first);
    expect($executorCalls)->toBe(1);

    $recordedBeforeSecond = count($evidence->all());
    $toolResultsBeforeSecond = count($toolResults);
    assertStreamedGateEvidenceIsCorrelated($first->invocationId);

    $second = $agent->stream('perform second operation');

    // As above: the throttled action leaves the executor count at 1 either way, so laziness is only
    // observable through evidence that has not been recorded yet.
    expect($evidence->all())->toHaveCount($recordedBeforeSecond)
        ->and($executorCalls)->toBe(1)
        ->and($toolResults)->toHaveCount($toolResultsBeforeSecond);

    iterator_to_array($second);

    expect(count($evidence->all()))->toBeGreaterThan($recordedBeforeSecond)
        ->and($executorCalls)->toBe(1);

    assertStreamedGateEvidenceIsCorrelated($second->invocationId, $recordedBeforeSecond);

    expect(collect($evidence->all())->where('stage', 'rate_limit')->pluck('disposition')->all())
        ->toBe(['permit', 'throttle']);

    assertStreamedDeniedToolResult($toolResults, 'operations.stream-rate-limit', 'throttle');
});

it('propagates an executor infrastructure failure without dispatching a tool result', function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
    $verdict = app(VerdictManager::class);
    $verdict->capability(streamedGateCapability(
        'operations.stream-executor-failure',
        function (AuthorizedAction $action): string {
            throw new RuntimeException('Executor infrastructure is unavailable.');
        },
    ));

    $tool = $verdict->bound(
        new StreamedGateDefinition,
        'operations.stream-executor-failure',
        new ActionContext('customer-72'),
    );
    $agent = streamedGateAgent(
        $tool,
        [new ToolCall('stream-executor-failure', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done'],
    );
    $toolResults = [];
    $toolInvoked = false;
    captureStreamedToolResults($tool, ['operation_id' => 1001], $toolResults);
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use ($tool, &$toolInvoked): void {
        if ($event->tool === $tool) {
            $toolInvoked = true;
        }
    });

    $response = $agent->stream('perform operation');

    expect($toolResults)->toBe([])
        ->and($toolInvoked)->toBeFalse();

    // Laravel AI v0.10.3 exposes no ToolFailed lifecycle event or failure callback; revisit this
    // positive assertion when upstream #874 lands. Until then, no ToolInvoked result proves the
    // exception was not normalized into Verdict's policy-refusal envelope.
    expect(fn (): array => iterator_to_array($response))
        ->toThrow(RuntimeException::class, 'Executor infrastructure is unavailable.')
        ->and($toolResults)->toBe([])
        ->and($toolInvoked)->toBeFalse()
        ->and(app(InvocationContext::class)->current())->toBeNull();
});

it('prevents a duplicate claim between two tool calls in one lazy streamed response', function (): void {
    $executorCalls = 0;
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
    $verdict = app(VerdictManager::class);
    $verdict->capability(streamedGateCapability(
        'operations.stream-two-tool-claim',
        function (AuthorizedAction $action) use (&$executorCalls): string {
            $executorCalls++;

            return 'executed';
        },
        ExecutionClaimPolicy::named(
            'stream-two-tool-operation',
            fn (ActionEnvelope $envelope, StreamedGateTarget $target): array => ['operation_id' => $target->id],
        ),
    ));

    $gateway = new StreamedGateTwoToolCallsGateway([
        new ToolCall('stream-two-tool-claim-first', 'StreamedGateDefinition', ['operation_id' => 1001]),
        new ToolCall('stream-two-tool-claim-duplicate', 'StreamedGateDefinition', ['operation_id' => 1001]),
    ]);
    $agent = streamedGateAgentWithTwoToolCalls(
        $verdict->bound(new StreamedGateDefinition, 'operations.stream-two-tool-claim', new ActionContext('customer-72')),
        $gateway,
    );
    $evidence = streamedGateEvidence();

    $response = $agent->stream('perform the operation twice');

    expect($executorCalls)->toBe(0)
        ->and($evidence->all())->toBe([])
        ->and($gateway->observedSteps)->toBe([]);

    iterator_to_array($response);

    expect($executorCalls)->toBe(1)
        ->and($gateway->observedSteps)->toBe([0, 1])
        ->and(collect($evidence->all())->where('stage', 'execution_claim')->pluck('disposition')->all())
        ->toBe(['permit', 'permit', 'deny']);

    assertStreamedGateEvidenceIsCorrelated($response->invocationId);
});

it('enforces a rate limit between two tool calls in one lazy streamed response', function (): void {
    $executorCalls = 0;
    $this->app->instance(Clock::class, new StreamedGateClock(
        new DateTimeImmutable('2026-08-01 12:00:15', new DateTimeZone('UTC')),
    ));
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
    $verdict = app(VerdictManager::class);
    $verdict->capability(streamedGateCapability(
        'operations.stream-two-tool-rate-limit',
        function (AuthorizedAction $action) use (&$executorCalls): string {
            $executorCalls++;

            return 'executed';
        },
        rateLimit: RateLimitPolicy::fixedWindow(
            name: 'one-two-tool-operation-per-customer',
            limit: 1,
            windowSeconds: 60,
            keyUsing: fn (ActionEnvelope $envelope): array => ['actor' => $envelope->context->actor],
        ),
    ));

    $gateway = new StreamedGateTwoToolCallsGateway([
        new ToolCall('stream-two-tool-limit-first', 'StreamedGateDefinition', ['operation_id' => 1001]),
        new ToolCall('stream-two-tool-limit-second', 'StreamedGateDefinition', ['operation_id' => 1002]),
    ]);
    $agent = streamedGateAgentWithTwoToolCalls(
        $verdict->bound(new StreamedGateDefinition, 'operations.stream-two-tool-rate-limit', new ActionContext('customer-72')),
        $gateway,
    );
    $evidence = streamedGateEvidence();

    $response = $agent->stream('perform two operations');

    expect($executorCalls)->toBe(0)
        ->and($evidence->all())->toBe([])
        ->and($gateway->observedSteps)->toBe([]);

    iterator_to_array($response);

    expect($executorCalls)->toBe(1)
        ->and($gateway->observedSteps)->toBe([0, 1])
        ->and(collect($evidence->all())->where('stage', 'rate_limit')->pluck('disposition')->all())
        ->toBe(['permit', 'throttle']);

    assertStreamedGateEvidenceIsCorrelated($response->invocationId);
});

it('resolves a callable action context during iteration rather than when the stream is created', function (): void {
    $contextResolutions = 0;
    $executorActor = null;
    $authorizationActors = [];
    $this->app->instance(CapabilityAuthorizer::class, new class($authorizationActors) implements CapabilityAuthorizer
    {
        /** @param list<string> $authorizationActors */
        public function __construct(private array &$authorizationActors) {}

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            $this->authorizationActors[] = $envelope->context->actor;

            return Decision::permit();
        }
    });

    $verdict = app(VerdictManager::class);
    $verdict->capability(streamedGateCapability(
        'operations.stream-context',
        function (AuthorizedAction $action) use (&$executorActor): string {
            $executorActor = $action->envelope->context->actor;

            return 'executed';
        },
    ));

    $agent = streamedGateAgent(
        $verdict->bound(
            new StreamedGateDefinition,
            'operations.stream-context',
            function (Request $request) use (&$contextResolutions): ActionContext {
                $contextResolutions++;

                return new ActionContext('customer-'.$contextResolutions);
            },
        ),
        [new ToolCall('stream-context', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done'],
    );

    $response = $agent->stream('perform operation');

    // The callable form is the streaming-specific hazard the compatibility matrix is about: Laravel
    // AI invokes it during lazy iteration, after the call that created the stream has returned.
    expect($contextResolutions)->toBe(0);

    iterator_to_array($response);

    // Laravel AI uses separate Request instances for preflight and execution. The prepared envelope
    // makes them observe one callable context, while Verdict still authorizes at both stages.
    expect($contextResolutions)->toBe(1)
        ->and($authorizationActors)->toBe(['customer-1', 'customer-1'])
        ->and($executorActor)->toBe('customer-1');

    assertStreamedGateEvidenceIsCorrelated($response->invocationId);
});
