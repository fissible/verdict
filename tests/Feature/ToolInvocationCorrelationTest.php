<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

/**
 * Records the order of lifecycle points and the ids visible at each one.
 */
final class CorrelationProbe
{
    /** @var array<int, array{event: string, tool_call_id: string|null, invocation_id: string|null}> */
    public static array $trace = [];

    public static function reset(): void
    {
        self::$trace = [];
    }

    public static function record(string $event, ?string $toolCallId = null, ?string $invocationId = null): void
    {
        self::$trace[] = [
            'event' => $event,
            'tool_call_id' => $toolCallId,
            'invocation_id' => $invocationId,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function events(): array
    {
        return array_column(self::$trace, 'event');
    }
}

final class ProbeTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Records the ids visible inside handle().';
    }

    public function handle(Request $request): Stringable|string
    {
        CorrelationProbe::record('handle', $request->toolCallId());

        return 'probed';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class ProbeAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Call the probe tool.';
    }

    /**
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return [new ProbeTool];
    }
}

final class InnerAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Call the probe tool.';
    }

    /**
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return [new ProbeTool];
    }
}

final class OuterAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Delegate to the inner agent.';
    }

    /**
     * @return array<int, Agent>
     */
    public function tools(): array
    {
        return [new InnerAgent];
    }
}

beforeEach(function (): void {
    CorrelationProbe::reset();

    Event::listen(InvokingTool::class, function (InvokingTool $event): void {
        CorrelationProbe::record('InvokingTool', $event->toolInvocationId, $event->invocationId);
    });

    Event::listen(ToolInvoked::class, function (ToolInvoked $event): void {
        CorrelationProbe::record('ToolInvoked', $event->toolInvocationId, $event->invocationId);
    });
});

it('dispatches InvokingTool before handle and ToolInvoked after it', function (): void {
    Ai::fakeAgent(ProbeAgent::class, [
        new ToolCall(id: 'call_alpha', name: 'ProbeTool', arguments: []),
        'done',
    ]);

    (new ProbeAgent)->prompt('go');

    expect(CorrelationProbe::events())->toBe(['InvokingTool', 'handle', 'ToolInvoked']);
});

it('reports whether the Request tool call id matches the dispatched invocation id', function (): void {
    Ai::fakeAgent(ProbeAgent::class, [
        new ToolCall(id: 'call_alpha', name: 'ProbeTool', arguments: []),
        'done',
    ]);

    (new ProbeAgent)->prompt('go');

    $byEvent = [];

    foreach (CorrelationProbe::$trace as $entry) {
        $byEvent[$entry['event']] = $entry['tool_call_id'];
    }

    expect($byEvent['handle'])->toBe('call_alpha')
        ->and($byEvent['InvokingTool'])->not->toBe('call_alpha')
        ->and($byEvent['InvokingTool'])->toBe($byEvent['ToolInvoked']);
});

it('cannot express more than one tool call per step through the fake gateway', function (): void {
    Ai::fakeAgent(ProbeAgent::class, [
        [
            new ToolCall(id: 'call_one', name: 'ProbeTool', arguments: []),
            new ToolCall(id: 'call_two', name: 'ProbeTool', arguments: []),
        ],
        'done',
    ]);

    (new ProbeAgent)->prompt('go');

    expect(array_count_values(CorrelationProbe::events())['handle'] ?? 0)->toBe(0);
});

it('hides the nested clobber when each agent is faked separately', function (): void {
    Ai::fakeAgent(InnerAgent::class, [
        new ToolCall(id: 'call_inner', name: 'ProbeTool', arguments: []),
        'inner done',
    ]);

    Ai::fakeAgent(OuterAgent::class, [
        new ToolCall(id: 'call_outer', name: 'InnerAgent', arguments: ['task' => 'go']),
        'outer done',
    ]);

    (new OuterAgent)->prompt('go');

    $trace = array_map(
        fn (array $entry): string => $entry['event'].':'.($entry['tool_call_id'] ?? 'null'),
        CorrelationProbe::$trace,
    );

    // The outer tool's handle() runs a whole nested generation before returning, so the
    // outer InvokingTool and ToolInvoked bracket the inner pair rather than sitting next to it.
    expect(CorrelationProbe::events())->toBe([
        'InvokingTool', 'InvokingTool', 'handle', 'ToolInvoked', 'ToolInvoked',
    ]);

    // Request::toolCallId() stays correct for the tool that is actually running.
    expect($trace[2])->toBe('handle:call_inner');

    // Each faked agent gets its own cloned provider, so the invocation id is never actually
    // shared and no clobbering is visible. Production memoizes one provider per name, where
    // the outer event does carry the inner id — see the shared-provider test below. A fake
    // returns green here for a defect that exists in production.
    [$outerInvoking, $innerInvoking] = [CorrelationProbe::$trace[0], CorrelationProbe::$trace[1]];
    [$innerInvoked, $outerInvoked] = [CorrelationProbe::$trace[3], CorrelationProbe::$trace[4]];

    expect($innerInvoked['tool_call_id'])->toBe($innerInvoking['tool_call_id'])
        ->and($outerInvoked['tool_call_id'])->toBe($outerInvoking['tool_call_id']);
});

it('isolates provider state under a fake in a way production does not', function (): void {
    // Production: providers are memoized per name, so nested generations share one instance
    // and therefore share GeneratesText::$currentToolInvocationId.
    expect(Ai::textProvider('openai'))->toBe(Ai::textProvider('openai'));

    // Faked: AiManager::textProviderFor() clones per resolution (AiManager.php:194), so each
    // agent gets its own provider and the shared mutable property is never actually shared.
    Ai::fakeAgent(ProbeAgent::class, ['done']);

    $agent = new ProbeAgent;

    expect(Ai::textProviderFor($agent))->not->toBe(Ai::textProviderFor($agent));
});

it('reports the nested tool invocation id on the outer ToolInvoked event', function (): void {
    // Resolving the provider directly and swapping the gateway onto it, rather than going
    // through Ai::fakeAgent(), keeps both agents on the one memoized instance that production
    // uses. A single gateway serves both generations, so the script is in nesting order.
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall(id: 'call_outer', name: 'InnerAgent', arguments: ['task' => 'go']),
        new ToolCall(id: 'call_inner', name: 'ProbeTool', arguments: []),
        'inner done',
        'outer done',
    ]));

    (new OuterAgent)->prompt('go');

    [$outerInvoking, $innerInvoking] = [CorrelationProbe::$trace[0], CorrelationProbe::$trace[1]];
    [$innerInvoked, $outerInvoked] = [CorrelationProbe::$trace[3], CorrelationProbe::$trace[4]];

    // GeneratesText::$currentToolInvocationId is one property on the provider. The nested
    // generation overwrites it and never restores it, so the outer tool's completion event
    // reports the inner tool's id. This is an upstream defect; the test pins it so a future
    // laravel/ai release that fixes it fails here rather than changing evidence silently.
    expect($outerInvoked['tool_call_id'])->toBe($innerInvoked['tool_call_id'])
        ->and($outerInvoked['tool_call_id'])->not->toBe($outerInvoking['tool_call_id']);

    // InvokingTool is dispatched in the same frame as the handle() it precedes, so it is
    // correct at the moment it fires. Only the trailing event is affected.
    expect($innerInvoking['tool_call_id'])->toBe($innerInvoked['tool_call_id']);

    // Request::toolCallId() is a parameter, not shared state, so it is never affected.
    expect(CorrelationProbe::$trace[2]['tool_call_id'])->toBe('call_inner');
});
