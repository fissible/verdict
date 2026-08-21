<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\LiveEvidenceReader;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\CapturingTool;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\CaseNotLiveExpressible;
use Fissible\Verdict\Evaluation\LiveAgentObserver;
use Fissible\Verdict\Evaluation\LiveObservationUnavailable;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\ModelDeclinedToAct;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\LaravelAi\InvocationContext;
use Fissible\Verdict\VerdictManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Tools\Request;

final readonly class LiveObserverTarget
{
    public function __construct(public int $id) {}
}

final class LiveObserverOrderLookup implements Tool
{
    public function description(): Stringable|string
    {
        return 'Look up an order by id.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'The Verdict-bound tool should handle this lookup.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class LiveObserverAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(private readonly Tool $tool) {}

    public function instructions(): Stringable|string
    {
        return 'Look up orders when asked.';
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
}

final class LiveObserverStructuredAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(private readonly Tool $tool) {}

    public function instructions(): Stringable|string
    {
        return 'Look up orders when asked and summarize the result.';
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

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['summary' => $schema->string()];
    }
}

/**
 * The one response shape the fake gateways above cannot express: an `AgentResponse` whose
 * `invocationId` is missing entirely. Laravel AI's provider always mints a UUIDv7 for that
 * property, so exercising the observer's "no invocation id" guard requires an `Agent`
 * implementation that bypasses the real generation loop and returns a bare response directly.
 */
final class LiveObserverBareAgent implements Agent
{
    public function instructions(): Stringable|string
    {
        return 'Respond without ever going through the real generation loop.';
    }

    public function prompt(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        return new AgentResponse('', 'No invocation id accompanies this response.', new Usage, new Meta('test', 'test-model'));
    }

    public function stream(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        throw new LogicException('This test agent only supports prompt().');
    }

    public function queue(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new LogicException('This test agent only supports prompt().');
    }

    public function broadcast(
        Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new LogicException('This test agent only supports prompt().');
    }

    public function broadcastNow(
        Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new LogicException('This test agent only supports prompt().');
    }

    public function broadcastOnQueue(
        Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new LogicException('This test agent only supports prompt().');
    }
}

final class StubLiveEvidenceReader implements LiveEvidenceReader
{
    /** @param list<DecisionEvidence> $decisions */
    public function __construct(private readonly array $decisions = []) {}

    /** @return list<DecisionEvidence> */
    public function decisionsFor(string $invocationId): array
    {
        return $this->decisions;
    }
}

function liveObserverPermitAllAuthorizer(): void
{
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
}

/** @param callable(AuthorizedAction): string $executor */
function liveObserverCapability(string $name, callable $executor): Capability
{
    return Capability::usingPolicy(
        name: $name,
        ability: 'read',
        resolveTarget: fn (ActionEnvelope $envelope): LiveObserverTarget => new LiveObserverTarget(
            (int) $envelope->proposal->arguments['order_id'],
        ),
    )->executionTarget(acceptTestSnapshot('live-observer-target'))->executeUsing($executor);
}

function liveObserverDecisionEvidence(string $capability, string $argumentFingerprint, string $disposition): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: 'live-observer-envelope',
        capability: $capability,
        stage: 'execution',
        disposition: $disposition,
        reason: null,
        argumentFingerprint: $argumentFingerprint,
        idempotencyKey: null,
        approvalReceiptFingerprint: null,
        approvalPhase: null,
        approvalOutcome: null,
        targetPolicy: null,
        targetStrategy: null,
        proposalTargetIdentityFingerprint: null,
        executionTargetIdentityFingerprint: null,
        targetIdentityMatched: null,
        rateLimitKeyFingerprint: null,
        rateLimitPolicy: null,
        rateLimitLimit: null,
        rateLimitRemaining: null,
        rateLimitResetAt: null,
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        executionClaimPolicy: null,
        executionClaimStatus: null,
        executionClaimAttempt: null,
        recordedAt: new DateTimeImmutable,
    );
}

/**
 * Builds the agent, then invokes it synchronously via `prompt()` and returns the response — the
 * same thing an application's own agent invoker does for `LiveAgentObserver`'s `$agentInvoker`.
 *
 * @return Closure(CaseInput): AgentResponse
 */
function liveObserverAgentFactory(LiveToolCapture $capture, string $capability): Closure
{
    return function (CaseInput $input) use ($capture, $capability): AgentResponse {
        $tool = app(VerdictManager::class)->bound(
            new LiveObserverOrderLookup,
            $capability,
            new ActionContext('customer-'.$input->trustedSetup['actor_id']),
        );

        $agent = new LiveObserverAgent(new CapturingTool($tool, $capability, $capture, app(ApprovalManager::class), app(InvocationContext::class)));

        /** @var string $request */
        $request = $input->untrustedInput['request'];

        return $agent->prompt($request);
    };
}

/** @return Closure(CaseInput): AgentResponse */
function liveObserverStructuredAgentFactory(LiveToolCapture $capture, string $capability): Closure
{
    return function (CaseInput $input) use ($capture, $capability): AgentResponse {
        $tool = app(VerdictManager::class)->bound(
            new LiveObserverOrderLookup,
            $capability,
            new ActionContext('customer-'.$input->trustedSetup['actor_id']),
        );

        $agent = new LiveObserverStructuredAgent(new CapturingTool($tool, $capability, $capture, app(ApprovalManager::class), app(InvocationContext::class)));

        /** @var string $request */
        $request = $input->untrustedInput['request'];

        return $agent->prompt($request);
    };
}

it('observes a synchronous agent response through the capture', function (): void {
    liveObserverPermitAllAuthorizer();

    app(VerdictManager::class)->capability(liveObserverCapability(
        'orders.read',
        fn (AuthorizedAction $action): string => 'Order 1001 is out for delivery.',
    ));

    LiveObserverAgent::fake([
        new ToolCall('live-observer-lookup', 'LiveObserverOrderLookup', ['order_id' => 1001]),
        'Order 1001 is out for delivery.',
    ]);

    $capture = new LiveToolCapture;
    $reader = new StubLiveEvidenceReader([
        liveObserverDecisionEvidence('orders.read', ArgumentFingerprint::make(['order_id' => 1001]), Disposition::Permit->value),
    ]);
    $observer = new LiveAgentObserver(liveObserverAgentFactory($capture, 'orders.read'), $capture, $reader);

    $observation = $observer(new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001?'],
    ));

    expect($observation->executed)->toBeTrue()
        ->and($observation->toolCalls)->toHaveCount(1)
        ->and($observation->toolCalls[0]->capability)->toBe('orders.read')
        ->and($observation->disposition)->toBe(Disposition::Permit);
});

it('reads the invocation id from a structured response', function (): void {
    liveObserverPermitAllAuthorizer();

    app(VerdictManager::class)->capability(liveObserverCapability(
        'orders.read-structured',
        fn (AuthorizedAction $action): string => 'Order 1001 is out for delivery.',
    ));

    LiveObserverStructuredAgent::fake([
        new ToolCall('live-observer-structured-lookup', 'LiveObserverOrderLookup', ['order_id' => 1001]),
        ['summary' => 'Order 1001 is out for delivery.'],
    ]);

    $capture = new LiveToolCapture;
    $reader = new StubLiveEvidenceReader([
        liveObserverDecisionEvidence('orders.read-structured', ArgumentFingerprint::make(['order_id' => 1001]), Disposition::Permit->value),
    ]);
    $observer = new LiveAgentObserver(liveObserverStructuredAgentFactory($capture, 'orders.read-structured'), $capture, $reader);

    $observation = $observer(new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001?'],
    ));

    expect($observation->executed)->toBeTrue();
});

it('throws CaseNotLiveExpressible when the case carries no request', function (): void {
    $observer = new LiveAgentObserver(
        function (CaseInput $input): never {
            throw new LogicException('The agent factory must not run when the case is not live-expressible.');
        },
        new LiveToolCapture,
        new StubLiveEvidenceReader,
    );

    expect(fn () => $observer(new CaseInput(trustedSetup: ['actor_id' => 72], untrustedInput: [])))
        ->toThrow(CaseNotLiveExpressible::class, 'cannot be expressed as a live prompt');
});

it('throws ModelDeclinedToAct when capture and evidence are both empty', function (): void {
    liveObserverPermitAllAuthorizer();

    app(VerdictManager::class)->capability(liveObserverCapability(
        'orders.read-declined',
        fn (AuthorizedAction $action): string => 'Order 1001 is out for delivery.',
    ));

    LiveObserverAgent::fake(['I could not find any information to answer that.']);

    $capture = new LiveToolCapture;
    $reader = new StubLiveEvidenceReader;
    $observer = new LiveAgentObserver(liveObserverAgentFactory($capture, 'orders.read-declined'), $capture, $reader);

    expect(fn () => $observer(new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001?'],
    )))->toThrow(ModelDeclinedToAct::class, 'without invoking a bound tool');
});

it('throws LiveObservationUnavailable when a captured call has no correlated evidence', function (): void {
    liveObserverPermitAllAuthorizer();

    app(VerdictManager::class)->capability(liveObserverCapability(
        'orders.read-uncorrelated',
        fn (AuthorizedAction $action): string => 'Order 1001 is out for delivery.',
    ));

    LiveObserverAgent::fake([
        new ToolCall('live-observer-uncorrelated-lookup', 'LiveObserverOrderLookup', ['order_id' => 1001]),
        'Order 1001 is out for delivery.',
    ]);

    $capture = new LiveToolCapture;
    // The harness is misconfigured: the reader has no evidence at all for a call that demonstrably
    // ran through the capture, so this must not be mistaken for an honest model decline.
    $reader = new StubLiveEvidenceReader;
    $observer = new LiveAgentObserver(liveObserverAgentFactory($capture, 'orders.read-uncorrelated'), $capture, $reader);

    expect(fn () => $observer(new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001?'],
    )))->toThrow(LiveObservationUnavailable::class, 'correlated decision evidence is missing');
});

it('does not decline when the capture is empty but the reader already holds evidence', function (): void {
    // This pins the AND in the decline guard: capture-empty plus evidence-empty is an honest
    // model decline, but capture-empty with non-empty evidence is neither a decline nor a
    // correlation failure per the observer's contract — it falls through to a vacuous
    // Observation. Dropping the `&& $decisions === []` half of the guard would misreport this
    // as ModelDeclinedToAct.
    app(VerdictManager::class)->capability(liveObserverCapability(
        'orders.read-vacuous',
        fn (AuthorizedAction $action): string => 'Order 1001 is out for delivery.',
    ));

    LiveObserverAgent::fake(['I could not find any information to answer that.']);

    $capture = new LiveToolCapture;
    $reader = new StubLiveEvidenceReader([
        liveObserverDecisionEvidence('orders.read-vacuous', ArgumentFingerprint::make(['order_id' => 1001]), Disposition::Permit->value),
    ]);
    $observer = new LiveAgentObserver(liveObserverAgentFactory($capture, 'orders.read-vacuous'), $capture, $reader);

    $observation = $observer(new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001?'],
    ));

    expect($observation->executed)->toBeFalse()
        ->and($observation->disposition)->toBeNull()
        ->and($observation->toolCalls)->toBe([]);
});

it('throws LiveObservationUnavailable when the response carries no invocation id', function (): void {
    $capture = new LiveToolCapture;
    $reader = new StubLiveEvidenceReader;
    $bareAgent = new LiveObserverBareAgent;
    $observer = new LiveAgentObserver(
        function (CaseInput $input) use ($bareAgent): AgentResponse {
            /** @var string $request */
            $request = $input->untrustedInput['request'];

            return $bareAgent->prompt($request);
        },
        $capture,
        $reader,
    );

    expect(fn () => $observer(new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001?'],
    )))->toThrow(LiveObservationUnavailable::class, 'no invocation id');
});
