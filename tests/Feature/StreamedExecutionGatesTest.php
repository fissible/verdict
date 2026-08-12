<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\Clock;
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

function streamedGateEvidence(): InMemoryEvidenceRecorder
{
    $evidence = app(EvidenceRecorder::class);

    if (! $evidence instanceof InMemoryEvidenceRecorder) {
        throw new RuntimeException('These cases require the in-memory evidence recorder bound by the test suite.');
    }

    return $evidence;
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

    $agent = streamedGateAgent(
        $verdict->bound(new StreamedGateDefinition, 'operations.stream-authorize', new ActionContext('customer-72')),
        [new ToolCall('stream-authorization', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done'],
    );

    $response = $agent->stream('perform operation');

    expect($authorizationCalls)->toBe(0)
        ->and($executorCalls)->toBe(0);

    iterator_to_array($response);

    expect($authorizationCalls)->toBe(2)
        ->and($executorCalls)->toBe(0);

    $evidence = streamedGateEvidence();

    expect(collect($evidence->all())->pluck('stage')->all())->toBe(['proposal', 'target_refresh', 'execution'])
        ->and(collect($evidence->all())->pluck('disposition')->all())->toBe(['permit', 'permit', 'deny']);
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

    $evidence = streamedGateEvidence();

    $first = $agent->stream('perform operation');
    expect($executorCalls)->toBe(0);
    iterator_to_array($first);
    expect($executorCalls)->toBe(1);

    $recordedBeforeDuplicate = count($evidence->all());
    $duplicate = $agent->stream('repeat operation');

    // The executor count cannot tell a lazy stream from an eager one here, because the duplicate is
    // denied either way. Recorded evidence can: an eager stream() would have run the duplicate's
    // gates, and recorded their dispositions, before iteration begins.
    expect($evidence->all())->toHaveCount($recordedBeforeDuplicate)
        ->and($executorCalls)->toBe(1);

    iterator_to_array($duplicate);

    expect(count($evidence->all()))->toBeGreaterThan($recordedBeforeDuplicate)
        ->and($executorCalls)->toBe(1);

    expect(collect($evidence->all())->where('stage', 'execution_claim')->pluck('disposition')->all())
        ->toBe(['permit', 'permit', 'deny']);
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

    $agent = streamedGateAgent(
        $verdict->bound(new StreamedGateDefinition, 'operations.stream-rate-limit', new ActionContext('customer-72')),
        [
            new ToolCall('stream-limit-first', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done',
            new ToolCall('stream-limit-second', 'StreamedGateDefinition', ['operation_id' => 1002]), 'done',
        ],
    );

    $evidence = streamedGateEvidence();

    $first = $agent->stream('perform first operation');
    expect($executorCalls)->toBe(0);
    iterator_to_array($first);
    expect($executorCalls)->toBe(1);

    $recordedBeforeSecond = count($evidence->all());
    $second = $agent->stream('perform second operation');

    // As above: the throttled action leaves the executor count at 1 either way, so laziness is only
    // observable through evidence that has not been recorded yet.
    expect($evidence->all())->toHaveCount($recordedBeforeSecond)
        ->and($executorCalls)->toBe(1);

    iterator_to_array($second);

    expect(count($evidence->all()))->toBeGreaterThan($recordedBeforeSecond)
        ->and($executorCalls)->toBe(1);

    expect(collect($evidence->all())->where('stage', 'rate_limit')->pluck('disposition')->all())
        ->toBe(['permit', 'throttle']);
});

it('resolves a callable action context during iteration rather than when the stream is created', function (): void {
    $contextResolutions = 0;
    $executorActor = null;
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
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

                return new ActionContext('customer-'.$request->all()['operation_id']);
            },
        ),
        [new ToolCall('stream-context', 'StreamedGateDefinition', ['operation_id' => 1001]), 'done'],
    );

    $response = $agent->stream('perform operation');

    // The callable form is the streaming-specific hazard the compatibility matrix is about: Laravel
    // AI invokes it during lazy iteration, after the call that created the stream has returned.
    expect($contextResolutions)->toBe(0);

    iterator_to_array($response);

    // Twice, not once: Laravel AI calls shouldRequestApproval() before handle(), and each builds its
    // own envelope. A resolver with side effects has to tolerate that.
    expect($contextResolutions)->toBe(2);
    expect($executorActor)->toBe('customer-1001');
});
