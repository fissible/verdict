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
use Laravel\Ai\Events\ToolFailed;
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

final class ThrowingProbeTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Throws, so the failure-path completion event can be observed.';
    }

    public function handle(Request $request): Stringable|string
    {
        CorrelationProbe::record('handle', $request->toolCallId());

        throw new RuntimeException('the tool handler failed');
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

/**
 * A tool that runs a nested generation and then throws, putting its own `ToolFailed` in the exact
 * trailing position the old shared-property defect corrupted.
 */
final class DelegatingThenFailingTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Delegates to an agent, then fails.';
    }

    public function handle(Request $request): Stringable|string
    {
        CorrelationProbe::record('handle', $request->toolCallId());

        (new ProbeAgent)->prompt('delegate');

        throw new RuntimeException('failed after delegating');
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class DelegatingThenFailingAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Call the delegating tool.';
    }

    /**
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return [new DelegatingThenFailingTool];
    }
}

/** The inner agent of the nesting scenario, but with a tool that throws. */
final class ThrowingInnerAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Call the throwing probe tool.';
    }

    /**
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return [new ThrowingProbeTool];
    }
}

final class ThrowingOuterAgent implements Agent, HasTools
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
        return [new ThrowingInnerAgent];
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

    Event::listen(ToolFailed::class, function (ToolFailed $event): void {
        CorrelationProbe::record('ToolFailed', $event->toolInvocationId, $event->invocationId);
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

it('cannot observe shared provider state when each agent is faked separately', function (): void {
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

    // Each faked agent gets its own cloned provider, so nothing is shared between the nested runs
    // and this arrangement could never have observed the shared-state defect that used to exist —
    // it returned green throughout the period when production was wrong. That is the point of
    // keeping it: it records why a fake-driven test was not evidence here, and why the
    // shared-provider test below (which uses the one memoized instance production uses) is the one
    // that pinned the defect and now asserts its fix. Upstream fixed the defect in laravel/ai#872;
    // this test's green was never contingent on that.
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

it('reports each tool invocation id on its own ToolInvoked event, including under a sub-agent', function (): void {
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

    // This asserted the *defect* until laravel/ai v0.11.0. `GeneratesText::$currentToolInvocationId`
    // was one property on a per-name-memoized provider, so a nested generation overwrote it and never
    // restored it, and the outer tool's completion event carried the *inner* tool's id — silently
    // mis-correlating the evidence Verdict writes from these events. Verdict pinned the broken
    // behaviour on purpose (#53) so an upstream fix would fail here rather than change the meaning of
    // recorded evidence without anyone noticing.
    //
    // The alarm fired as designed. laravel/ai#872 deleted the shared property and scoped the id
    // through a RunContext, so the assertion is now the fixed behaviour: each tool's completion event
    // reports its own id, and the outer one is no longer clobbered by the nested run. Keep asserting
    // it — this is the correlation guarantee Verdict's provenance depends on, not a historical note.
    // (The fix arrived via #872, not the superseded draft #848 it was split out of.)
    expect($outerInvoked['tool_call_id'])->toBe($outerInvoking['tool_call_id'])
        ->and($outerInvoked['tool_call_id'])->not->toBe($innerInvoked['tool_call_id']);

    // InvokingTool was always dispatched in the same frame as the handle() it precedes, so it was
    // correct even while the trailing event was not. It still is.
    expect($innerInvoking['tool_call_id'])->toBe($innerInvoked['tool_call_id']);

    // Request::toolCallId() is a parameter, not shared state, so it is never affected.
    expect(CorrelationProbe::$trace[2]['tool_call_id'])->toBe('call_inner');

    // Re-verified against v0.11.0's new contracts, because RecordToolResultProvenance correlates
    // tool-result provenance by exactly this id. #871 threads one invocation id through an entire
    // agent run and #875 links a sub-agent back to its parent — but a sub-agent run still gets its
    // *own* invocation id plus a parent pointer, rather than inheriting the parent's. So a
    // sub-agent's tool results correlate to the sub-agent's run, which is what Verdict records.
    expect($outerInvoking['invocation_id'])->toBe($outerInvoked['invocation_id'])
        ->and($innerInvoking['invocation_id'])->toBe($innerInvoked['invocation_id'])
        ->and($outerInvoked['invocation_id'])->not->toBe($innerInvoked['invocation_id']);
});

/**
 * The failure-path mirror of the correlation guarantee above, and the deferred half of
 * [#130](https://github.com/fissible/verdict/issues/130).
 *
 * `ToolFailed` reaches Verdict through the same trailing-event position that carried the defect
 * `ToolInvoked` used to have: it fires *after* a nested generation has run, which is exactly the
 * condition under which the old shared `GeneratesText::$currentToolInvocationId` was clobbered.
 * laravel/ai#872 made `$toolInvocationId` a local in `InvokesTools::executeTool()` handed to both
 * `toolFailed()` and `toolInvoked()`, so the same fix covers both — but "should correlate for the
 * same reason" is an inference, and failure-path evidence is the last place to leave one unasserted.
 */
it('reports each tool invocation id on its own ToolFailed event, including under a sub-agent', function (): void {
    // One memoized provider serves both generations, as in the success-path test above — going
    // through Ai::fakeAgent() would clone per resolution and hide any shared-state defect.
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall(id: 'call_outer', name: 'ThrowingInnerAgent', arguments: ['task' => 'go']),
        new ToolCall(id: 'call_inner', name: 'ThrowingProbeTool', arguments: []),
    ]));

    // The inner tool throws, the inner run propagates it out of the sub-agent, and the outer tool
    // call fails in turn — so both failure events fire, the outer one after the nested run.
    // Measured, not assumed: the generation loop turns a throwing tool into a failed *tool result*
    // rather than letting it escape, so the sub-agent returns normally and the outer tool call
    // succeeds. The outer completion event therefore still lands in the trailing position that used
    // to be clobbered — now following a nested run that *failed*.
    (new ThrowingOuterAgent)->prompt('go');

    expect(CorrelationProbe::events())->toBe([
        'InvokingTool', 'InvokingTool', 'handle', 'ToolFailed', 'ToolInvoked',
    ], 'A tool failure inside a sub-agent is reported as a failure there, and does not fail the outer call.');

    [$outerInvoking, $innerInvoking] = [CorrelationProbe::$trace[0], CorrelationProbe::$trace[1]];
    [$innerFailed, $outerInvoked] = [CorrelationProbe::$trace[3], CorrelationProbe::$trace[4]];

    expect($innerFailed['tool_call_id'])->toBe($innerInvoking['tool_call_id'], 'The failure reports the tool that threw.')
        ->and($outerInvoked['tool_call_id'])->toBe($outerInvoking['tool_call_id'], 'The outer completion is not clobbered by the nested failure.')
        ->and($outerInvoked['tool_call_id'])->not->toBe($innerFailed['tool_call_id']);

    expect($innerFailed['invocation_id'])->toBe($innerInvoking['invocation_id'])
        ->and($outerInvoked['invocation_id'])->not->toBe($innerFailed['invocation_id'], 'Each run keeps its own invocation id.');
});

/**
 * The exact mirror of the defect, on the failure path: a tool that runs a nested generation and
 * *then* throws, so its own `ToolFailed` is the trailing event after the nested run — the position
 * where the shared `GeneratesText::$currentToolInvocationId` used to have been overwritten.
 */
it('reports the failing tool own id when it throws after a nested generation', function (): void {
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall(id: 'call_outer', name: 'DelegatingThenFailingTool', arguments: []),
        new ToolCall(id: 'call_nested', name: 'ProbeTool', arguments: []),
        'nested done',
        'outer done',
    ]));

    // Measured difference from the sub-agent case above: a tool that throws at the top level of a
    // run propagates out of prompt(), because nothing between it and the caller absorbs it — where a
    // tool that throws *inside* a sub-agent is absorbed and reported as that sub-agent's tool result.
    // Either way the failure event fires first, which is all this test is about.
    expect(fn () => (new DelegatingThenFailingAgent)->prompt('go'))
        ->toThrow(RuntimeException::class, 'failed after delegating');

    expect(CorrelationProbe::events())->toBe([
        'InvokingTool', 'handle', 'InvokingTool', 'handle', 'ToolInvoked', 'ToolFailed',
    ], 'The failing tool reports last, after the generation it nested.');

    [$outerInvoking, $nestedInvoking] = [CorrelationProbe::$trace[0], CorrelationProbe::$trace[2]];
    [$nestedInvoked, $outerFailed] = [CorrelationProbe::$trace[4], CorrelationProbe::$trace[5]];

    expect($outerFailed['tool_call_id'])->toBe($outerInvoking['tool_call_id'], 'The failure must name the tool that threw, not the one its nested run invoked.')
        ->and($outerFailed['tool_call_id'])->not->toBe($nestedInvoked['tool_call_id'])
        ->and($nestedInvoked['tool_call_id'])->toBe($nestedInvoking['tool_call_id']);

    expect($outerFailed['invocation_id'])->toBe($outerInvoking['invocation_id'])
        ->and($outerFailed['invocation_id'])->not->toBe($nestedInvoked['invocation_id']);
});
