<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CapabilityNotAttempted;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\ResourceCheckpointCapture;
use Fissible\Verdict\Evaluation\ResourceDigest;
use Fissible\Verdict\Evaluation\ResourceIdentity;
use Fissible\Verdict\Evidence\CanonicalJson;
use Fissible\Verdict\VerdictManager;
use Illuminate\Database\DatabaseManager;
use Workbench\App\Storefront\Customer;
use Workbench\App\Storefront\StorefrontOrders;

/**
 * #295 — the check-to-use swap, measured against the real two-call flow.
 *
 * Verdict decides every tool call at execution time, so the plan-time form of TOCTOU is already
 * defeated. What is unbound is the value-level gap: a resource is read and authorized against in one
 * call, and a later call acts on a resource an attacker swapped in the interval.
 *
 * WHY THE TEST OWNS EVERYTHING. `tests/Unit/ResourceCheckpointAssertionTest.php` pins what the
 * comparison MEANS, but every observation there is hand-built, so it cannot show a capture ever ran.
 * Routing through a scenario-runner method would move the same problem up a level: a runner can
 * manufacture both digests, the execution flags, and the observation, and every assertion still
 * passes with no capture at the executor. So this test wires its own sink, drives both calls itself,
 * and performs the mutation itself. Nothing here is reported to the test; everything is either
 * observed from the sink or done in the test body on a line you can read.
 *
 * WHY THIS FIXTURE. Two executions of `orders.ledger-read`, a capability added for this spec alone.
 * Every other record-keyed capability resolves from the in-memory `Catalog`, whose `Order` is
 * `final readonly` — and that immutability, which is deliberate and worth keeping, makes those
 * capabilities unusable here: the attack IS the resource changing between two resolutions, and an
 * immutable fixture has nothing to change. `orders.ledger-read` instead loads the row from
 * `storefront_orders` by primary key in both its resolver and its `refreshUsing`, so the test can
 * update that row with a plain UPDATE between the two calls. The record stays immutable; the
 * external source of truth behind it moves, which is the realistic shape of the threat.
 *
 * `orders.cancel` would have been the natural-sounding second call and is the wrong one twice over:
 * it resolves from the immutable catalog, and it is confirmation-gated, so it cannot execute without
 * an issued and approved receipt — and a use that never executes produces no second endpoint.
 *
 * THE INDEPENDENT SOURCE. Expected digests are built from hand-written projections of what order
 * 1003 held before and after the mutation, never by calling the capture path. This is the rule
 * `StorefrontOrderSearchTest` already states for predicates — "deriving it by calling the same
 * builder path the executor uses would make the comparison pass by construction, the
 * incident-runbook rule" — applied to resource projections. Canonicalization is shared deliberately:
 * a hash function is not what has to be independent, the CONTENT is, and the two expected contents
 * are required to hash differently.
 *
 * WHAT IT DOES NOT SHOW. A detected mismatch is not a prevented one. Nothing here refuses the use —
 * the boundary permits both calls, and the swap is visible only afterwards, in the evaluation.
 */
function swapEnvelope(int $orderId = 1003): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal('orders.ledger-read', ['order_id' => $orderId]),
        new ActionContext(new Customer(72, 'Rowan Petty'), ['tenant_id' => 'storefront-demo']),
    );
}

/** Seeds the external store the ledger capability reads. */
function swapPrepared(): void
{
    StorefrontOrders::prepare(app(DatabaseManager::class)->connection());
}

/**
 * The swap: an UPDATE against the external store, performed by the test. It travels through no
 * capture, executor, resolver, or Verdict path — which is what makes the two endpoints independent
 * readings rather than two views of one post-mutation value.
 */
function swapTheRow(): void
{
    app(DatabaseManager::class)->connection()
        ->table(StorefrontOrders::TABLE)
        ->where('id', 1003)
        ->update(['item' => 'Swapped in the gap']);
}

/**
 * Order 1003 as the fixture holds it before any mutation. Hand-written; if the fixture changes this
 * must be updated by hand, and the failure is the point.
 *
 * @return array<string, mixed>
 */
function swapPreDisclosure(): array
{
    return ['id' => 1003, 'customer_id' => 72, 'item' => 'Wireless travel mouse', 'status' => 'delivered'];
}

/** @return array<string, mixed> */
function swapPostDisclosure(): array
{
    // Only `item` moves. Identity is held constant on purpose: a swap that changed the id would be a
    // different record, and the pairing would correctly refuse to compare them.
    return ['id' => 1003, 'customer_id' => 72, 'item' => 'Swapped in the gap', 'status' => 'delivered'];
}

function swapExpectedDigest(array $disclosure): string
{
    return ResourceDigest::SCHEME.':'.hash('sha256', CanonicalJson::encode($disclosure));
}

/**
 * The expected identity, DERIVED from the capability's own declaration rather than restated here.
 *
 * Writing the three fields out by hand would be a parallel declaration: an implementation that
 * hard-coded the same tenant/type/id shape would satisfy it while ignoring the policy entirely.
 * Asking the registered capability for its `ExecutionTargetPolicy` and calling `identity()` makes
 * the policy's result the sole input, which is the contract the capture has to honour.
 *
 * This is not the tautology the digest comparison has to avoid. There, deriving the expectation from
 * the executor's own path would prove nothing about the CONTENT. Here the policy IS the declared
 * source of truth for identity, so deriving from it is the point rather than a shortcut.
 */
function swapResourceIdentity(): string
{
    $verdict = app(VerdictManager::class);
    $capability = $verdict->registeredCapability('orders.ledger-read');
    $policy = $capability->executionTargetPolicy();
    $envelope = swapEnvelope();

    return ResourceIdentity::for(
        $policy->identity($envelope, $capability->resolveTarget($envelope)),
    );
}

/** A sink the TEST owns, so what it reads is what the executor emitted. */
function swapSink(): LiveToolCapture
{
    $sink = new LiveToolCapture;

    app()->instance(ResourceCheckpointCapture::class, new ResourceCheckpointCapture($sink, 'order-disclosure'));

    return $sink;
}

it('captures the pre-swap projection at the check and the post-swap one at the use', function (): void {
    $sink = swapSink();

    swapPrepared();

    $check = app(VerdictManager::class)->runBound(swapEnvelope());
    expect($check->executed)->toBeTrue();

    swapTheRow();

    $use = app(VerdictManager::class)->runBound(swapEnvelope());
    expect($use->executed)->toBeTrue();

    $resources = $sink->resources();
    $calls = $sink->toolObservations();

    expect($resources)->toHaveCount(2)
        ->and($calls)->toHaveCount(2);

    // The load-bearing assertion of this whole issue: a capture that read the resource only once,
    // after the mutation, would report the POST-swap digest at both endpoints and fail here.
    expect($resources[0]->digest)->toBe(swapExpectedDigest(swapPreDisclosure()))
        ->and($resources[1]->digest)->toBe(swapExpectedDigest(swapPostDisclosure()))
        ->and($resources[0]->digest)->not->toBe($resources[1]->digest);

    // Same record across the gap — the bytes moved, the identity did not.
    expect($resources[0]->resourceIdentity)->toBe(swapResourceIdentity())
        ->and($resources[1]->resourceIdentity)->toBe($resources[0]->resourceIdentity);

    // Each endpoint belongs to a DISTINCT execution that actually ran, matched by sequence rather
    // than by capability or argument fingerprint. Without this the pair could both have come from
    // one call, or from none.
    expect($resources[0]->executionSequence)->toBe($calls[0]->executionSequence)
        ->and($resources[1]->executionSequence)->toBe($calls[1]->executionSequence)
        ->and($resources[0]->executionSequence)->not->toBe($resources[1]->executionSequence);

    // Same capability twice: the case a fingerprint could not express, and the reason the binding is
    // a per-execution sequence.
    expect($calls[0]->capability)->toBe('orders.ledger-read')
        ->and($calls[0]->executed)->toBeTrue()
        ->and($calls[1]->capability)->toBe('orders.ledger-read')
        ->and($calls[1]->executed)->toBeTrue();
});

it('reports the swap through the assertion, not only in the raw captures', function (): void {
    $sink = swapSink();

    swapPrepared();
    app(VerdictManager::class)->runBound(swapEnvelope());
    swapTheRow();
    app(VerdictManager::class)->runBound(swapEnvelope());

    $observation = new Observation(
        disposition: null,
        executed: true,
        toolCalls: $sink->toolObservations(),
        resources: $sink->resources(),
    );

    // What a pack case will state: declare the two endpoints, and fail when the bytes moved.
    expect(Assertions::resourceDigestMatchesPriorObservation('order-disclosure', swapResourceIdentity(), 1, 2)
        ->evaluate($observation)->passed)->toBeFalse();
});

it('holds on the unmodified flow, with both calls still resolving the resource', function (): void {
    $sink = swapSink();

    // The utility twin: identical in every respect except the mutation. A mechanism that reported a
    // mismatch here would read every benign two-step trajectory as an attack, which in a security
    // pack is worse than having no case at all.
    swapPrepared();
    app(VerdictManager::class)->runBound(swapEnvelope());
    app(VerdictManager::class)->runBound(swapEnvelope());

    $resources = $sink->resources();

    expect($resources)->toHaveCount(2)
        ->and($resources[0]->digest)->toBe(swapExpectedDigest(swapPreDisclosure()))
        ->and($resources[1]->digest)->toBe($resources[0]->digest)
        ->and($resources[0]->executionSequence)->not->toBe($resources[1]->executionSequence);

    $observation = new Observation(
        disposition: null,
        executed: true,
        toolCalls: $sink->toolObservations(),
        resources: $resources,
    );

    expect(Assertions::resourceDigestMatchesPriorObservation('order-disclosure', swapResourceIdentity(), 1, 2)
        ->evaluate($observation)->passed)->toBeTrue();
});

it('observes nothing at all when the capture is not wired', function (): void {
    // #251's lesson: a harness that omits its wiring lands unmeasured, never silently passing. The
    // sink is REAL and its adapter is deliberately not bound, so the emptiness asserted below is
    // observed rather than hand-authored — an `Observation` built with `resources: []` would prove
    // only that an empty array is empty.
    // The seam the executor reaches must be genuinely ABSENT. A private sink that was never
    // connected stays empty whatever happens, so its emptiness is a fact about this variable rather
    // than about the run — a real capture emitting to another instance would satisfy it.
    app()->forgetInstance(ResourceCheckpointCapture::class);

    expect(app()->bound(ResourceCheckpointCapture::class))->toBeFalse();

    $sink = new LiveToolCapture;

    swapPrepared();

    // Both calls must still RUN. An unattached sink staying empty proves nothing if neither endpoint
    // executed — that would be a test of the flow failing, dressed as a test of the wiring.
    $check = app(VerdictManager::class)->runBound(swapEnvelope());
    swapTheRow();
    $use = app(VerdictManager::class)->runBound(swapEnvelope());

    expect($check->executed)->toBeTrue()
        ->and($use->executed)->toBeTrue()
        ->and($sink->resources())->toBeEmpty();

    $observation = new Observation(
        disposition: null,
        executed: true,
        toolCalls: $sink->toolObservations(),
        resources: $sink->resources(),
    );

    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('order-disclosure', swapResourceIdentity(), 1, 2)
        ->evaluate($observation))->toThrow(CapabilityNotAttempted::class);
});
