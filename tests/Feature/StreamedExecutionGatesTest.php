<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

final readonly class StreamedGateTarget
{
    public function __construct(public int $id) {}
}

final class StreamedGateDefinition implements Tool
{
    public int $invocations = 0;

    public function description(): Stringable|string
    {
        return 'Perform the protected operation.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->invocations++;

        return 'The bound executor should handle this operation.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class StreamedGateAgent implements Agent, HasTools
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

    $definition = new StreamedGateDefinition;
    $agent = streamedGateAgent(
        $verdict->bound($definition, 'operations.stream-authorize', new ActionContext('customer-72')),
        [new ToolCall('stream-authorization', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done'],
    );

    $response = $agent->stream('perform operation');

    expect($authorizationCalls)->toBe(0)
        ->and($executorCalls)->toBe(0);

    iterator_to_array($response);

    expect($authorizationCalls)->toBe(2)
        ->and($executorCalls)->toBe(0)
        ->and($definition->invocations)->toBe(0);

    $evidence = app(EvidenceRecorder::class);
    expect($evidence)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if ($evidence instanceof InMemoryEvidenceRecorder) {
        expect(collect($evidence->all())->pluck('stage')->all())->toBe(['proposal', 'target_refresh', 'execution'])
            ->and(collect($evidence->all())->pluck('disposition')->all())->toBe(['permit', 'permit', 'deny']);
    }
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

    $agent = streamedGateAgent(
        $verdict->bound(new StreamedGateDefinition, 'operations.stream-claim', new ActionContext('customer-72')),
        [
            new ToolCall('stream-claim-first', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done',
            new ToolCall('stream-claim-duplicate', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done',
        ],
    );

    $first = $agent->stream('perform operation');
    expect($executorCalls)->toBe(0);
    iterator_to_array($first);

    $duplicate = $agent->stream('repeat operation');
    expect($executorCalls)->toBe(1);
    iterator_to_array($duplicate);

    expect($executorCalls)->toBe(1);

    $evidence = app(EvidenceRecorder::class);
    expect($evidence)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if ($evidence instanceof InMemoryEvidenceRecorder) {
        expect(collect($evidence->all())->where('stage', 'execution_claim')->pluck('disposition')->all())
            ->toBe(['permit', 'permit', 'deny']);
    }
});

it('consumes and enforces a semantic rate limit through Agent streaming', function (): void {
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

    $agent = streamedGateAgent(
        $verdict->bound(new StreamedGateDefinition, 'operations.stream-rate-limit', new ActionContext('customer-72')),
        [
            new ToolCall('stream-limit-first', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done',
            new ToolCall('stream-limit-second', 'StreamedGateDefinition', ['operation_id' => 1002]), 'done',
        ],
    );

    $first = $agent->stream('perform first operation');
    expect($executorCalls)->toBe(0);
    iterator_to_array($first);

    $second = $agent->stream('perform second operation');
    expect($executorCalls)->toBe(1);
    iterator_to_array($second);

    expect($executorCalls)->toBe(1);

    $evidence = app(EvidenceRecorder::class);
    expect($evidence)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if ($evidence instanceof InMemoryEvidenceRecorder) {
        expect(collect($evidence->all())->where('stage', 'rate_limit')->pluck('disposition')->all())
            ->toBe(['permit', 'throttle']);
    }
});
