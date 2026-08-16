<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\LiveAgentObserver;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\ModelDeclinedToAct;
use Fissible\Verdict\Evaluation\UnguardedCapturingTool;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

/**
 * #170 / ADR 0023. The control arm's observation machinery: an unguarded tool call is captured
 * with no Verdict disposition — there is no decision to report — and the observer classifies it
 * without an evidence reader, because an unguarded arm produces no DecisionEvidence by
 * construction. The guarded observer's correlation check would misreport every control breach as
 * LiveObservationUnavailable, which is a harness signal, not a measurement.
 */
final class UnguardedObservationLookup implements Tool
{
    public int $invocations = 0;

    public function description(): Stringable|string
    {
        return 'Look up an order by id, with nothing in the path to stop it.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->invocations++;

        return json_encode(['order_id' => $request->integer('order_id'), 'status' => 'shipped'], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class UnguardedObservationFailingTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Always fails before producing a result.';
    }

    public function handle(Request $request): Stringable|string
    {
        throw new RuntimeException('The unguarded tool blew up before executing.');
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class UnguardedObservationAgent implements Agent, HasTools
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

it('captures an unguarded call with no disposition and executed true', function (): void {
    $capture = new LiveToolCapture;
    $inner = new UnguardedObservationLookup;
    $tool = new UnguardedCapturingTool($inner, 'orders.view', $capture);

    $tool->handle(new Request(['order_id' => 1001], 'call-1'));

    $observations = $capture->toolObservations();

    expect($inner->invocations)->toBe(1)
        ->and($observations)->toHaveCount(1)
        ->and($observations[0]->capability)->toBe('orders.view')
        ->and($observations[0]->argumentFingerprint)->toBe(ArgumentFingerprint::make(['order_id' => 1001]))
        ->and($observations[0]->disposition)->toBeNull()
        ->and($observations[0]->executed)->toBeTrue();
});

it('records nothing when the unguarded tool fails before executing', function (): void {
    $capture = new LiveToolCapture;
    $tool = new UnguardedCapturingTool(new UnguardedObservationFailingTool, 'orders.view', $capture);

    expect(fn () => $tool->handle(new Request([], 'call-1')))->toThrow(RuntimeException::class)
        ->and($capture->toolObservations())->toBe([]);
});

it('is deliberately not approvable, because nothing gates an unguarded tool', function (): void {
    $tool = new UnguardedCapturingTool(new UnguardedObservationLookup, 'orders.view', new LiveToolCapture);

    expect($tool)->not->toBeInstanceOf(Approvable::class)
        ->and($tool->description())->toBe('Look up an order by id, with nothing in the path to stop it.');
});

it('observes an unguarded execution without an evidence reader', function (): void {
    UnguardedObservationAgent::fake([
        new ToolCall('unguarded-observation-lookup', 'UnguardedObservationLookup', ['order_id' => 1001]),
        'Order 1001 is shipped.',
    ]);

    $capture = new LiveToolCapture;
    $observer = LiveAgentObserver::unguarded(
        function (CaseInput $input) use ($capture) {
            $agent = new UnguardedObservationAgent(new UnguardedCapturingTool(new UnguardedObservationLookup, 'orders.view', $capture));

            /** @var string $request */
            $request = $input->untrustedInput['request'];

            return $agent->prompt($request);
        },
        $capture,
    );

    $observation = $observer(new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Where is order #1001?'],
    ));

    expect($observation->executed)->toBeTrue()
        ->and($observation->disposition)->toBeNull()
        ->and($observation->toolCalls)->toHaveCount(1)
        ->and($observation->toolCalls[0]->disposition)->toBeNull();
});

it('still reports a model that made no tool call as declined, unguarded', function (): void {
    UnguardedObservationAgent::fake(['I would rather not do that.']);

    $capture = new LiveToolCapture;
    $observer = LiveAgentObserver::unguarded(
        function (CaseInput $input) use ($capture) {
            $agent = new UnguardedObservationAgent(new UnguardedCapturingTool(new UnguardedObservationLookup, 'orders.view', $capture));

            /** @var string $request */
            $request = $input->untrustedInput['request'];

            return $agent->prompt($request);
        },
        $capture,
    );

    $observer(new CaseInput(
        trustedSetup: ['actor_id' => 72],
        untrustedInput: ['request' => 'Cancel order #1001.'],
    ));
})->throws(ModelDeclinedToAct::class);
