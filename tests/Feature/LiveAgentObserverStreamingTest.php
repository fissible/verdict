<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\CapturingTool;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\LiveAgentObserver;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Tools\Request;

// StubLiveEvidenceReader is declared once, globally, by LiveAgentObserverTest.php. Pest runs the
// whole suite in a single process (phpunit.xml.dist: processIsolation="false"), so redeclaring it
// here would be a fatal "cannot redeclare class" — reuse it instead.

final readonly class LiveObserverStreamingTarget
{
    public function __construct(public int $id) {}
}

final class LiveObserverStreamingOrderLookup implements Tool
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

final class LiveObserverStreamingAgent implements Agent, HasTools
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

/**
 * The one response shape `Agent::fake()` cannot express: a provider that fails while a lazy
 * stream is being iterated. Laravel AI's real generation loop, response, and stream machinery
 * remain in play; only the text-generation gateway is swapped out.
 */
final class LiveObserverStreamingExplodingGateway implements StepTextGateway
{
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
     * @return Generator<int, never, mixed, StepResponse>
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
        // This function body is lexically a generator (see the unreachable `yield` below), so
        // calling it returns a Generator object immediately without running any of this code.
        // The exception only fires once the caller actually iterates — i.e. during the
        // observer's iterator_to_array($response), not while $agent->stream() is building the
        // lazy response. That is exactly the "provider fails during consumption" scenario.
        throw new RuntimeException('provider exploded');
        yield; // unreachable; its only purpose is to keep this function a generator
    }
}

function liveObserverStreamingPermitAllAuthorizer(): void
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
function liveObserverStreamingCapability(string $name, callable $executor): Capability
{
    return Capability::usingPolicy(
        name: $name,
        ability: 'read',
        resolveTarget: fn (ActionEnvelope $envelope): LiveObserverStreamingTarget => new LiveObserverStreamingTarget(
            (int) $envelope->proposal->arguments['order_id'],
        ),
    )->executionTarget(acceptTestSnapshot('live-observer-streaming-target'))->executeUsing($executor);
}

function liveObserverStreamingDecisionEvidence(string $capability, string $argumentFingerprint, string $disposition): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: 'live-observer-streaming-envelope',
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
 * Builds the agent, then invokes it via `stream()` and returns the lazy `StreamableAgentResponse`
 * unconsumed — proving the observer, not this factory, is responsible for iterating it.
 *
 * @return Closure(CaseInput): StreamableAgentResponse
 */
function liveObserverStreamingAgentFactory(LiveToolCapture $capture, string $capability, int $orderId): Closure
{
    return function (CaseInput $input) use ($capture, $capability, $orderId): StreamableAgentResponse {
        $tool = app(VerdictManager::class)->bound(
            new LiveObserverStreamingOrderLookup,
            $capability,
            new ActionContext('customer-'.$input->trustedSetup['actor_id']),
        );

        LiveObserverStreamingAgent::fake([
            new ToolCall('live-observer-streaming-lookup', 'LiveObserverStreamingOrderLookup', ['order_id' => $orderId]),
            'done',
        ]);

        $agent = new LiveObserverStreamingAgent(new CapturingTool($tool, $capability, $capture, app(ApprovalManager::class), app(InvocationContext::class)));

        /** @var string $request */
        $request = $input->untrustedInput['request'];

        return $agent->stream($request);
    };
}

it('fully consumes a streamed response before classifying it', function (): void {
    liveObserverStreamingPermitAllAuthorizer();

    app(VerdictManager::class)->capability(liveObserverStreamingCapability(
        'orders.read-stream',
        fn (AuthorizedAction $action): string => 'Order 1001 is out for delivery.',
    ));

    $capture = new LiveToolCapture;
    $reader = new StubLiveEvidenceReader([
        liveObserverStreamingDecisionEvidence('orders.read-stream', ArgumentFingerprint::make(['order_id' => 1001]), Disposition::Permit->value),
    ]);
    $observer = new LiveAgentObserver(
        liveObserverStreamingAgentFactory($capture, 'orders.read-stream', 1001),
        $capture,
        $reader,
    );

    $observation = $observer(new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001?'],
    ));

    expect($observation->executed)->toBeTrue()
        ->and($observation->toolCalls)->toHaveCount(1);
});

it('propagates a provider failure during consumption as its own class', function (): void {
    Ai::textProvider('openai')->useTextGateway(new LiveObserverStreamingExplodingGateway);

    app(VerdictManager::class)->capability(liveObserverStreamingCapability(
        'orders.read-stream-exploding',
        fn (AuthorizedAction $action): string => 'This executor must never run: the provider fails before any tool call.',
    ));

    $capture = new LiveToolCapture;
    $reader = new StubLiveEvidenceReader;
    $agent = new LiveObserverStreamingAgent(new CapturingTool(
        app(VerdictManager::class)->bound(
            new LiveObserverStreamingOrderLookup,
            'orders.read-stream-exploding',
            new ActionContext('customer-72'),
        ),
        'orders.read-stream-exploding',
        $capture,
        app(ApprovalManager::class),
        app(InvocationContext::class),
    ));
    $observer = new LiveAgentObserver(
        function (CaseInput $input) use ($agent): StreamableAgentResponse {
            /** @var string $request */
            $request = $input->untrustedInput['request'];

            return $agent->stream($request);
        },
        $capture,
        $reader,
    );

    expect(fn () => $observer(new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001?'],
    )))->toThrow(RuntimeException::class, 'provider exploded');
});

it('does not carry evidence from a previous trial into the next', function (): void {
    $authorizationCalls = 0;
    app()->instance(CapabilityAuthorizer::class, new class($authorizationCalls) implements CapabilityAuthorizer
    {
        public function __construct(private int &$authorizationCalls) {}

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            $this->authorizationCalls++;

            // Both proposal and execution stages of the first trial's single tool call permit
            // (calls 1 and 2); every stage from the second trial onward denies (call 3+).
            return $this->authorizationCalls <= 2
                ? Decision::permit()
                : Decision::deny('Authority changed before the second trial.');
        }
    });

    app(VerdictManager::class)->capability(liveObserverStreamingCapability(
        'orders.read-stream-isolated',
        fn (AuthorizedAction $action): string => 'Order 1001 is out for delivery.',
    ));

    $capture = new LiveToolCapture;
    $reader = new StubLiveEvidenceReader([
        liveObserverStreamingDecisionEvidence('orders.read-stream-isolated', ArgumentFingerprint::make(['order_id' => 1001]), Disposition::Permit->value),
        liveObserverStreamingDecisionEvidence('orders.read-stream-isolated', ArgumentFingerprint::make(['order_id' => 1001]), Disposition::Deny->value),
    ]);
    $observer = new LiveAgentObserver(
        liveObserverStreamingAgentFactory($capture, 'orders.read-stream-isolated', 1001),
        $capture,
        $reader,
    );

    $permittedInput = new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001?'],
    );
    $deniedInput = new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001, again?'],
    );

    $first = $observer($permittedInput);
    $second = $observer($deniedInput);

    expect($first->executed)->toBeTrue()
        ->and($second->executed)->toBeFalse()
        ->and($second->toolCalls)->toHaveCount(1);
});
