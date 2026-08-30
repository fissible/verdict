<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CapabilityNotAttempted;
use Fissible\Verdict\Evaluation\CapturingTool;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\RegisteredSecretScanner;
use Fissible\Verdict\Evaluation\ResourceCheckpointCapture;
use Fissible\Verdict\Evaluation\ResourceDigest;
use Fissible\Verdict\Evaluation\ResourceIdentity;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Targets\ResourceProjection;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * #393: the resource checkpoint records its `executed: true` endpoint BEFORE the executor runs,
 * and mints a `ToolObservation` that `CapturingTool` later mints again for the same call.
 *
 * Two defects with one root: the checkpoint is treating "I am about to execute" as "an execution
 * happened", and it is doing so in a place that is not the only place executions are recorded.
 *
 *   - A throwing executor leaves a completed-looking endpoint behind. `Assertions` pairs a
 *     `ResourceObservation` to a `ToolObservation` by execution sequence AND `executed === true`
 *     (`Assertions::resourceExecutionsObserved()`), so a check/use comparison can be reported
 *     against an execution that never finished. For an instrument whose entire job is to say
 *     whether the bytes changed between check and use, "the use never happened" is not a detail.
 *   - When the checkpoint sink and `CapturingTool` share one `LiveToolCapture`, one executed call
 *     yields two observations and `toolCallCount(x, 1)` counts 2. Nothing in this repository builds
 *     that combination today, which is why it has never failed; the tests below establish that the
 *     two instruments compose rather than describing a consumer that already exists.
 *
 * WHAT MUST NOT BREAK, and it is the whole difficulty. The checkpoint's endpoint is not
 * incidental: #392's tests rely on it to attribute a run to a capability, and
 * `StorefrontCheckToUseSwapTest` pairs two resource endpoints to two executions by sequence and
 * asserts both are executed. So the endpoint cannot simply stop existing — a `runBound()` flow has
 * no `CapturingTool`, so nothing else would record that anything ran, and every resource
 * comparison would silently become unmeasured.
 *
 * That excludes deleting the observation, not relocating it. `ResourceCheckpointCapture` is free to
 * stop OWNING the record, provided something on the execution path still commits one after the
 * executor succeeds and the pre-execution digest keeps its sequence linkage to it. These tests are
 * written against that outcome rather than against a particular owner.
 *
 * The digest itself must still be taken before the executor. That is the point of a check-to-use
 * measurement, and these tests would be measuring nothing if the projection moved after the
 * mutation it exists to catch.
 */
/**
 * Supplies the tool surface `bound()` wraps. It does not decide whether execution fails: `bound()`
 * runs the CAPABILITY's `executeUsing()` executor, and refuses a capability without one, so the
 * failure the combined tests need is declared there rather than here.
 */
final class CheckpointEndpointProbeTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Probe.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'ran';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

function checkpointEndpointEnvelope(string $capability): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal($capability, ['order_id' => 1]),
        new ActionContext('customer-72'),
    );
}

/** The checkpoint armed on a sink, the way a harness arms it. */
function checkpointEndpointSink(): LiveToolCapture
{
    $sink = new LiveToolCapture;

    app()->instance(ResourceCheckpointCapture::class, new ResourceCheckpointCapture($sink, 'order-row'));

    return $sink;
}

function permitCheckpointEndpoint(): void
{
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
}

/** A capability the checkpoint will observe: it declares both a target policy and a projection. */
function checkpointEndpointCapability(string $name, ?callable $executor = null): Capability
{
    $capability = Capability::usingPolicy(
        name: $name,
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): array => ['id' => 1, 'item' => 'mouse'],
    )->executionTarget(ExecutionTargetPolicy::refresh(
        name: $name.'-target',
        identityUsing: fn (ActionEnvelope $envelope, array $target): array => [
            'resource_type' => 'order',
            'resource_id' => $target['id'],
        ],
        refreshUsing: fn (ActionEnvelope $envelope, array $target): array => $target,
    ))->resourceProjection(ResourceProjection::declared(
        'checkpoint-order/v1',
        fn (ActionEnvelope $envelope, array $target): array => ['item' => $target['item']],
    ));

    return $executor === null ? $capability : $capability->executeUsing($executor);
}

/**
 * The same capability with NO projection, so the checkpoint declines to observe it. It keeps the
 * execution-target policy: `bound()` refuses a capability without one outright
 * (CapabilityNotExecutable), so dropping both would test a capability that cannot run rather than
 * one the checkpoint ignores.
 */
function checkpointEndpointUnprojectedCapability(string $name): Capability
{
    return Capability::usingPolicy(
        name: $name,
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): array => ['id' => 1, 'item' => 'mouse'],
    )->executionTarget(ExecutionTargetPolicy::refresh(
        name: $name.'-target',
        identityUsing: fn (ActionEnvelope $envelope, array $target): array => [
            'resource_type' => 'order',
            'resource_id' => $target['id'],
        ],
        refreshUsing: fn (ActionEnvelope $envelope, array $target): array => $target,
    ));
}

/**
 * Each resource endpoint is paired to its OWN completed execution, in order.
 *
 * `resourceExecutionsObserved()` only asks whether each resource sequence matches SOME executed
 * call, so an implementation that emitted two calls and attached both endpoints to the first one's
 * sequence satisfies every count and measurability assertion here. Two endpoints of one execution
 * is not a check-to-use pair — it is the same instant read twice — so the distinctness is the
 * property, not a detail of it.
 */
function checkpointEndpointPairedInOrder(LiveToolCapture $sink, string $capability): bool
{
    $executed = array_map(
        fn ($call): int => $call->executionSequence,
        checkpointEndpointExecuted($sink, $capability),
    );
    $endpoints = array_map(fn ($resource): int => $resource->executionSequence, $sink->resources());

    return count(array_unique($endpoints)) === count($endpoints) && $endpoints === $executed;
}

/** @return list<mixed> */
function checkpointEndpointExecuted(LiveToolCapture $sink, string $capability): array
{
    return array_values(array_filter(
        $sink->toolObservations(),
        fn ($call): bool => $call->capability === $capability && $call->executed,
    ));
}

function checkpointEndpointObservation(LiveToolCapture $sink): Observation
{
    return new Observation(
        disposition: null,
        executed: true,
        toolCalls: $sink->toolObservations(),
        resources: $sink->resources(),
    );
}

function checkpointEndpointIdentity(): string
{
    return ResourceIdentity::for(['resource_type' => 'order', 'resource_id' => 1]);
}

/**
 * Whether the check/use comparison is MEASURABLE, read through the assertion an evaluation
 * actually runs rather than through the raw captures.
 *
 * `resourceDigestMatchesPriorObservation()` pairs each endpoint to an executed tool observation by
 * sequence and raises CapabilityNotAttempted when it cannot — that is the "unmeasured" outcome, and
 * it is the one the whole issue turns on. Reporting a clean comparison across an execution that
 * threw is worse than reporting nothing, because the run looks measured.
 */
function checkpointEndpointComparisonMeasured(LiveToolCapture $sink): bool
{
    try {
        Assertions::resourceDigestMatchesPriorObservation(
            checkpoint: 'order-row',
            resourceIdentity: checkpointEndpointIdentity(),
            projection: 'checkpoint-order/v1',
            checkOccurrence: 1,
            useOccurrence: 2,
        )->evaluate(checkpointEndpointObservation($sink));

        return true;
    } catch (CapabilityNotAttempted) {
        return false;
    }
}

it('records an executed endpoint when the executor completes', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();

    app(VerdictManager::class)->capability(checkpointEndpointCapability(
        'orders.read',
        fn (AuthorizedAction $action): string => 'ran',
    ));

    $result = app(VerdictManager::class)->runBound(checkpointEndpointEnvelope('orders.read'));

    // The positive control every assertion below depends on. Without it, an implementation that
    // recorded no endpoint at all would satisfy each "no executed endpoint" test while destroying
    // the instrument.
    expect($result->executed)->toBeTrue()
        ->and($sink->resources())->toHaveCount(1)
        ->and(checkpointEndpointExecuted($sink, 'orders.read'))->toHaveCount(1);
});

it('records no executed endpoint when the executor throws', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();
    $reached = 0;

    app(VerdictManager::class)->capability(checkpointEndpointCapability(
        'orders.read',
        function (AuthorizedAction $action) use (&$reached): string {
            $reached++;

            throw new RuntimeException('executor failed');
        },
    ));

    try {
        app(VerdictManager::class)->runBound(checkpointEndpointEnvelope('orders.read'));
    } catch (Throwable) {
        // The failure is the subject; how the manager surfaces it is not this test's business.
    }

    // The executor was actually reached and actually failed. Catching Throwable would otherwise let
    // a run that died before executing — a misconfigured capability, a denied decision — pass as
    // the failure path this test claims to describe. That mistake was live in two of these tests
    // before it was caught.
    expect($reached)->toBe(1);

    // The intrinsic half of #393. "About to execute" was being written down as "executed", so a
    // failed call left an endpoint indistinguishable from a completed one.
    expect(checkpointEndpointExecuted($sink, 'orders.read'))->toBe([]);
});

it('reports the comparison unmeasured when the second execution threw', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();

    $fail = false;
    $reached = 0;

    app(VerdictManager::class)->capability(checkpointEndpointCapability(
        'orders.read',
        function (AuthorizedAction $action) use (&$fail, &$reached): string {
            $reached++;

            if ($fail) {
                throw new RuntimeException('executor failed');
            }

            return 'ran';
        },
    ));

    app(VerdictManager::class)->runBound(checkpointEndpointEnvelope('orders.read'));

    $fail = true;

    try {
        app(VerdictManager::class)->runBound(checkpointEndpointEnvelope('orders.read'));
    } catch (Throwable) {
        // The failure is the subject; how the manager surfaces it is not this test's business.
    }

    // The sharp form of the defect, and the reason a bare "no executed observation" assertion is
    // not enough on its own. Both endpoints exist and both were projected, so the comparison has
    // two occurrences to work with — and today it will happily answer, because the second call's
    // endpoint was marked executed before the executor that failed. The honest answer is that this
    // run has nothing to compare: the use never happened.
    // Both executors ran: the first to completion, the second into its throw. Without this the
    // test could pass because the second run never started.
    expect($reached)->toBe(2)
        ->and($sink->resources())->toHaveCount(2)
        ->and(checkpointEndpointComparisonMeasured($sink))->toBeFalse();
});

it('pairs the resource endpoint to the execution that completed', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();

    app(VerdictManager::class)->capability(checkpointEndpointCapability(
        'orders.read',
        fn (AuthorizedAction $action): string => 'ran',
    ));

    app(VerdictManager::class)->runBound(checkpointEndpointEnvelope('orders.read'));
    app(VerdictManager::class)->runBound(checkpointEndpointEnvelope('orders.read'));

    // The regression guard for the fix that would otherwise be obvious. Dropping the checkpoint's
    // observation kills the double-count and also kills this: a runBound() flow has no
    // CapturingTool, so nothing else records that anything ran, and every check-to-use comparison
    // silently becomes unmeasured. Two runs, because the comparison needs two endpoints.
    expect($sink->resources())->toHaveCount(2)
        ->and(checkpointEndpointExecuted($sink, 'orders.read'))->toHaveCount(2)
        ->and(checkpointEndpointComparisonMeasured($sink))->toBeTrue()
        ->and(checkpointEndpointPairedInOrder($sink, 'orders.read'))->toBeTrue();
});

/**
 * The combined wiring, which nothing in this repository builds today — and that is precisely why
 * the double-count is latent rather than failing. `grep ResourceCheckpointCapture workbench/app`
 * finds nothing, so the shipped harness has a `CapturingTool` and no checkpoint, while
 * `StorefrontCheckToUseSwapTest` has a checkpoint and drives `runBound()` without a
 * `CapturingTool`. Each half is only ever observed alone.
 *
 * So these tests do not describe an existing consumer; they establish a compatibility contract that
 * the two instruments compose on one sink. That is worth stating plainly rather than calling the
 * combination "intended": a live evaluation that wants both a check-to-use digest and a tool-call
 * count is the obvious next harness, and the point of fixing this now is that the composition is
 * sound before something depends on it. Put them on one sink today and a single executed call is
 * counted twice.
 *
 * One departure from `StorefrontLiveAgent`, stated rather than left to be discovered: it interposes
 * `SideEffectRelayTool` between `CapturingTool` and the bound tool. That wrapper relays side
 * effects and does not touch the ordering or the recording seam this file is about, so it is
 * omitted — which does mean this is the relevant slice of the shipped stack, not the whole of it.
 */
function checkpointEndpointCapturingTool(string $capability, LiveToolCapture $sink): CapturingTool
{
    return new CapturingTool(
        app(VerdictManager::class)->bound(
            new CheckpointEndpointProbeTool,
            $capability,
            new ActionContext('customer-72'),
        ),
        $capability,
        $sink,
        app(ApprovalManager::class),
        app(InvocationContext::class),
    );
}

function checkpointEndpointCallCount(LiveToolCapture $sink, string $capability, int $count): bool
{
    return Assertions::toolCallCount($capability, $count)
        ->evaluate(checkpointEndpointObservation($sink))
        ->passed;
}

it('counts one tool call when the checkpoint and the capturing tool share a sink', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();

    app(VerdictManager::class)->capability(checkpointEndpointCapability(
        'orders.read',
        fn (AuthorizedAction $action): string => 'ran',
    ));

    checkpointEndpointCapturingTool('orders.read', $sink)->handle(new Request([], 'call-393'));

    // One model-invoked call, one execution, one observation. Two is not a cosmetic miscount: every
    // live assertion that counts calls — and the rate at which a case is judged to have acted —
    // reads this list.
    expect(checkpointEndpointExecuted($sink, 'orders.read'))->toHaveCount(1)
        ->and(checkpointEndpointCallCount($sink, 'orders.read', 1))->toBeTrue();
});

it('counts one tool call for a capability the checkpoint does not observe', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();

    app(VerdictManager::class)->capability(
        checkpointEndpointUnprojectedCapability('orders.plain')
            ->executeUsing(fn (AuthorizedAction $action): string => 'ran'),
    );

    checkpointEndpointCapturingTool('orders.plain', $sink)->handle(new Request([], 'call-393'));

    // The control that keeps the fix from being "count one fewer, always". This capability declares
    // no projection, so the checkpoint records nothing and `CapturingTool` is the only recorder —
    // the count was already right here and must stay right.
    expect($sink->resources())->toBe([])
        ->and(checkpointEndpointExecuted($sink, 'orders.plain'))->toHaveCount(1)
        ->and(checkpointEndpointCallCount($sink, 'orders.plain', 1))->toBeTrue();
});

it('keeps the comparison measurable under the combined wiring', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();

    app(VerdictManager::class)->capability(checkpointEndpointCapability(
        'orders.read',
        fn (AuthorizedAction $action): string => 'ran',
    ));

    checkpointEndpointCapturingTool('orders.read', $sink)->handle(new Request([], 'call-a'));
    checkpointEndpointCapturingTool('orders.read', $sink)->handle(new Request([], 'call-b'));

    // Deduplicating is only half the requirement; the surviving observation still has to be the one
    // the resource endpoints pair against. A fix that dropped the checkpoint's sequence in favour of
    // CapturingTool's would count correctly here and leave every check-to-use comparison unmeasured.
    expect($sink->resources())->toHaveCount(2)
        ->and(checkpointEndpointCallCount($sink, 'orders.read', 2))->toBeTrue()
        ->and(checkpointEndpointComparisonMeasured($sink))->toBeTrue()
        // Distinct executions, in order: collapsing both endpoints onto one call would pass every
        // other assertion in this test while destroying the comparison it exists to enable.
        ->and(checkpointEndpointPairedInOrder($sink, 'orders.read'))->toBeTrue();
});

it('records no executed endpoint under the combined wiring when the executor throws', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();

    $reached = 0;

    app(VerdictManager::class)->capability(checkpointEndpointCapability(
        'orders.read',
        function (AuthorizedAction $action) use (&$reached): string {
            $reached++;

            throw new RuntimeException('executor failed');
        },
    ));

    try {
        checkpointEndpointCapturingTool('orders.read', $sink)
            ->handle(new Request([], 'call-393'));
    } catch (Throwable) {
        // The throw propagates through the decorator, so CapturingTool never records either.
    }

    expect($reached)->toBe(1);

    // No executed tool observation from either recorder: the checkpoint must not have committed
    // one before the executor, and CapturingTool never reaches its own record() because the throw
    // propagates through the decorator. A resource observation may well remain — retaining the
    // pre-execution digest is required — and that is fine: with nothing executed to pair it to,
    // the run reads as unmeasured, which is the honest answer.
    expect(checkpointEndpointExecuted($sink, 'orders.read'))->toBe([])
        ->and(checkpointEndpointCallCount($sink, 'orders.read', 0))->toBeTrue();
});

it('captures the resource digest before the executor mutates it', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();

    $item = 'mouse';
    $reached = 0;

    $capability = Capability::usingPolicy(
        name: 'orders.mutate',
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): array => ['id' => 1],
    )->executionTarget(ExecutionTargetPolicy::refresh(
        name: 'orders.mutate-target',
        identityUsing: fn (ActionEnvelope $envelope, array $target): array => [
            'resource_type' => 'order',
            'resource_id' => $target['id'],
        ],
        refreshUsing: fn (ActionEnvelope $envelope, array $target): array => $target,
    ))->resourceProjection(ResourceProjection::declared(
        'checkpoint-order/v1',
        // By reference, and it is load-bearing: an arrow function would capture $item by value at
        // definition time and report 'mouse' whenever the projection ran, so the guard would pass
        // against a capture moved after the executor and prove nothing.
        function (ActionEnvelope $envelope, array $target) use (&$item): array {
            return ['item' => $item];
        },
    ))->executeUsing(function (AuthorizedAction $action) use (&$item, &$reached): string {
        $reached++;
        $item = 'keyboard';

        return 'ran';
    });

    app(VerdictManager::class)->capability($capability);
    app(VerdictManager::class)->runBound(checkpointEndpointEnvelope('orders.mutate'));

    // The timing this whole instrument depends on, and nothing else here tests it: every other
    // executor in this file leaves the projected value alone, so an implementation that solved the
    // "executed before the executor" problem by moving the CAPTURE after a successful execution
    // would satisfy all of them. It would also destroy the measurement — a check-to-use digest
    // taken after the mutation reports the post-swap bytes at both endpoints and can never detect a
    // swap. Delaying when the endpoint counts as EXECUTED is the fix; delaying when the resource is
    // READ is the opposite of it.
    expect($reached)->toBe(1)
        ->and($sink->resources())->toHaveCount(1)
        ->and($sink->resources()[0]->digest)->toBe(ResourceDigest::for(['item' => 'mouse']))
        ->and($sink->resources()[0]->digest)->not->toBe(ResourceDigest::for(['item' => 'keyboard']));
});

it('records the execution endpoint on the run path as well as the bound one', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();
    $reached = 0;

    app(VerdictManager::class)->capability(checkpointEndpointCapability('orders.read'));

    $result = app(VerdictManager::class)->run(
        checkpointEndpointEnvelope('orders.read'),
        // run() hands its executor the Evaluation, not the AuthorizedAction runBound() passes —
        // which is itself a reason to cover both rather than assume the paths are interchangeable.
        function (Evaluation $evaluation) use (&$reached): string {
            $reached++;

            return 'ran';
        },
    );

    // `run()` is a public entry point of its own, and the contract these tests state is about the
    // execution path rather than about `runBound()`. The two share a private helper today, so this
    // is cheap — but a claim that holds only because of an implementation detail is one that
    // stops holding when the detail changes.
    expect($reached)->toBe(1)
        ->and($result->executed)->toBeTrue()
        ->and($sink->resources())->toHaveCount(1)
        ->and(checkpointEndpointExecuted($sink, 'orders.read'))->toHaveCount(1);
});

it('records no executed endpoint on the run path when the executor throws', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();
    $reached = 0;

    app(VerdictManager::class)->capability(checkpointEndpointCapability('orders.read'));

    try {
        app(VerdictManager::class)->run(
            checkpointEndpointEnvelope('orders.read'),
            function (Evaluation $evaluation) use (&$reached): string {
                $reached++;

                throw new RuntimeException('executor failed');
            },
        );
    } catch (Throwable) {
    }

    expect($reached)->toBe(1)
        ->and(checkpointEndpointExecuted($sink, 'orders.read'))->toBe([]);
});

it('shows no completed execution to the executor while it is still running', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();

    $duringResources = null;
    $duringExecuted = null;

    app(VerdictManager::class)->capability(checkpointEndpointCapability(
        'orders.read',
        function (AuthorizedAction $action) use ($sink, &$duringResources, &$duringExecuted): string {
            $duringResources = count($sink->resources());
            $duringExecuted = count(checkpointEndpointExecuted($sink, 'orders.read'));

            return 'ran';
        },
    ));

    app(VerdictManager::class)->runBound(checkpointEndpointEnvelope('orders.read'));

    // Record-then-retract would satisfy every other test in this file: write the executed endpoint
    // up front, delete it if the executor throws, and the after-the-fact assertions all hold. This
    // is the one that separates it from recording on success, and the distinction is not academic —
    // the sink is shared, so anything reading it mid-flight (a nested capture, the executor itself,
    // a future instrument) is told an execution completed while it is still in progress.
    //
    // The digest, by contrast, must already be there: it is taken before the executor by design.
    expect($duringResources)->toBe(1)
        ->and($duringExecuted)->toBe(0)
        ->and(checkpointEndpointExecuted($sink, 'orders.read'))->toHaveCount(1);
});

/**
 * A test amendment agreed after the freeze, recorded as such rather than folded in quietly.
 *
 * Deduplicating the two observations means one of them survives, and review found that the
 * surviving one was the checkpoint's — which cannot know anything `CapturingTool` scanned for. The
 * registered-secret canary (ADR 0032) is carried only by the tool's record, so collapsing to the
 * checkpoint's silently emptied it. `CapturingTool`'s own docblock explains why that is worse than
 * a wrong answer: an empty armed set is what lets the assertion REFUSE to answer rather than pass,
 * so the canary would not fail, it would go permanently blind under the combined wiring.
 *
 * The frozen spec asked only for one observation. It should have asked for one observation that
 * still knows everything both recorders knew.
 */
it('keeps the registered-secret scan when the two observations collapse into one', function (): void {
    permitCheckpointEndpoint();
    $sink = checkpointEndpointSink();

    app(VerdictManager::class)->capability(checkpointEndpointCapability(
        'orders.read',
        fn (AuthorizedAction $action): string => 'ran',
    ));

    $tool = new CapturingTool(
        app(VerdictManager::class)->bound(
            new CheckpointEndpointProbeTool,
            'orders.read',
            new ActionContext('customer-72'),
        ),
        'orders.read',
        $sink,
        app(ApprovalManager::class),
        app(InvocationContext::class),
        new RegisteredSecretScanner(['order-canary' => 'CANARY-7f3a91e4b2']),
    );

    $tool->handle(new Request(['note' => 'contains CANARY-7f3a91e4b2 verbatim'], 'call-393'));

    $executed = checkpointEndpointExecuted($sink, 'orders.read');

    // Still one observation — and still the one that saw the canary. An implementation that keeps
    // the checkpoint's record and drops the tool's passes every other test in this file.
    expect($executed)->toHaveCount(1)
        ->and($executed[0]->registeredSecretLabels)->toBe(['order-canary'])
        ->and($executed[0]->matchedRegisteredSecrets)->toBe(['order-canary']);
});
