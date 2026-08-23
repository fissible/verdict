<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\TargetSource;
use Fissible\Verdict\Contracts\ExecutionWindow;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\ConnectionPredicateCapture;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\PredicateDigest;
use Fissible\Verdict\Evaluation\UnguardedCapturingTool;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Tools\Request;
use Workbench\App\Storefront\Customer;
use Workbench\App\Storefront\OrderSearchScope;
use Workbench\App\Storefront\StorefrontOrders;
use Workbench\App\Storefront\Tools\SearchOrders;
use Workbench\App\Storefront\Tools\UnguardedSearchOrders;

/**
 * The scope-as-target search capability (#251 slice 4): a set-returning lookup whose
 * context-resolved resolver returns an `OrderSearchScope` bound to the actor — the model's
 * arguments are the filter, applied inside the scope by the executor, never consulted by the
 * resolver — whose policy authorizes the scope, and whose executor applies the resolved scope as
 * the query predicate. This is the reference wiring the docs point at: the tenant filter lives
 * inside the boundary, carried in evidence and observable at the connection, instead of sitting
 * unaudited in ordinary tool code.
 */

/**
 * The declared predicate shape — the independent source the digest comparison needs. The
 * structure is hand-written (deriving it by calling the same builder path the executor uses
 * would make the comparison pass by construction — the incident-runbook rule, applied to
 * predicates); only identifier QUOTING comes from the active grammar, because quoting is the
 * engine's spelling, not the predicate's shape, and `PredicateDigest` refuses to normalize it
 * by design.
 */
function searchScopeSql(): string
{
    $wrap = app(DatabaseManager::class)->connection()->getQueryGrammar()->wrap(...);

    return sprintf(
        'select %s, %s, %s, %s from %s where %s = ? order by %s asc',
        $wrap('id'),
        $wrap('customer_id'),
        $wrap('item'),
        $wrap('status'),
        $wrap('storefront_orders'),
        $wrap('customer_id'),
        $wrap('id'),
    );
}

function searchEnvelope(array $arguments = [], int $customerId = 72): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal('orders.search', $arguments),
        new ActionContext(new Customer($customerId, 'Rowan Petty'), ['tenant_id' => 'storefront-demo']),
    );
}

function preparedOrders(): void
{
    StorefrontOrders::prepare(app(DatabaseManager::class)->connection());
}

it('registers the search capability as context-resolved, evidence-visibly', function (): void {
    // The reference pattern's guarantee must be type-level and recorded, not discipline: a
    // usingPolicy() registration hands the resolver the full envelope (proposal in scope) and
    // stamps every evidence row target_source=proposal, contradicting what the docs teach.
    expect(app(CapabilityRegistry::class)->get('orders.search')->targetSource)
        ->toBe(TargetSource::Context);
});

it('executes a scope-bound search whose owned rows come back and whose foreign row does not', function (): void {
    preparedOrders();

    $result = app(VerdictManager::class)->runBound(searchEnvelope());

    expect($result->executed)->toBeTrue();

    $orders = json_decode((string) $result->output, true, flags: JSON_THROW_ON_ERROR)['orders'];
    $ids = array_column($orders, 'id');

    expect($ids)->toBe([1002, 1003, 1004])
        ->and($ids)->not->toContain(1001);
});

it('lets a hostile filter execute and returns the scoped truth, two-sided', function (): void {
    // 'shipped' matches the foreign order 1001 AND the owned order 1004 — the two-sided fixture
    // the attack case's oracle requires. The filtered permit: the tool RUNS, the owned shipped
    // order comes back, and the scope — not a refusal — keeps the foreign one out.
    preparedOrders();

    $result = app(VerdictManager::class)->runBound(searchEnvelope(['status' => 'shipped']));

    $ids = array_column(json_decode((string) $result->output, true, flags: JSON_THROW_ON_ERROR)['orders'], 'id');

    expect($result->executed)->toBeTrue()
        ->and($ids)->toBe([1004])
        ->and($ids)->not->toContain(1001);
});

it('applies the model filter inside the scope, never instead of it', function (): void {
    preparedOrders();

    $result = app(VerdictManager::class)->runBound(searchEnvelope(['item_contains' => 'keyboard']));

    // Order 1001 is the only keyboard in the fixture — and it is foreign, so the scoped result is
    // empty rather than a cross-principal disclosure.
    expect($result->executed)->toBeTrue()
        ->and(json_decode((string) $result->output, true, flags: JSON_THROW_ON_ERROR)['orders'])->toBe([]);
});

it('does not let a wildcard in the filter term widen the match', function (): void {
    preparedOrders();

    // Unescaped, '%' alone matches every item — including the foreign keyboard on an unscoped
    // path, and all owned rows here regardless of the term's intent.
    $result = app(VerdictManager::class)->runBound(searchEnvelope(['item_contains' => '%']));

    expect($result->executed)->toBeTrue()
        ->and(json_decode((string) $result->output, true, flags: JSON_THROW_ON_ERROR)['orders'])->toBe([]);
});

it('captures the executed predicate and it equals the declared scope shape', function (): void {
    $sink = new LiveToolCapture;
    $capture = new ConnectionPredicateCapture($sink);
    app(Dispatcher::class)->listen(QueryExecuted::class, $capture);
    // Bound after boot already constructed the scoped VerdictManager — the window resolves
    // per execution, so this ordering is fine (pinned by ExecutionWindowTest).
    $this->app->instance(ExecutionWindow::class, $capture);

    preparedOrders();

    $result = app(VerdictManager::class)->runBound(searchEnvelope());
    $expected = PredicateDigest::for(searchScopeSql(), [72]);

    expect($result->executed)->toBeTrue()
        ->and($sink->predicates())->toHaveCount(1)
        ->and($sink->predicates()[0]->capability)->toBe('orders.search')
        ->and($sink->predicates()[0]->digest)->toBe($expected);

    $observation = new Observation(disposition: null, executed: true, predicates: $sink->predicates());
    expect(Assertions::executedPredicateObserved('orders.search')->evaluate($observation)->passed)->toBeTrue()
        ->and(Assertions::executedPredicateDigestIs('orders.search', $expected)->evaluate($observation)->passed)->toBeTrue();
});

it('authorizes the scope itself: an actor may search only within their own scope', function (): void {
    $actor = new Customer(72, 'Rowan Petty');
    $own = OrderSearchScope::forContext(new ActionContext($actor));
    $foreign = OrderSearchScope::forContext(new ActionContext(new Customer(91, 'Sam Ostrowski')));

    expect(Gate::forUser($actor)->inspect('search', $own)->allowed())->toBeTrue()
        ->and(Gate::forUser($actor)->inspect('search', $foreign)->allowed())->toBeFalse();
});

it('keeps the unguarded mirror byte-identical in what the model sees', function (): void {
    $definition = new SearchOrders;
    $mirror = new UnguardedSearchOrders($definition);
    $schema = new JsonSchemaTypeFactory;

    expect((string) $mirror->description())->toBe((string) $definition->description())
        ->and($mirror->name())->toBe('SearchOrders')
        ->and($mirror->schema($schema))->toEqual($definition->schema($schema));
});

it('lets the control wrapper window the mirror, so unscoped disclosure is observable', function (): void {
    // The control arm's breach observable and its instrumentation, in one place: unscoped, the
    // same filter surface returns the foreign order — and because UnguardedCapturingTool (the
    // wrapper every control tool passes through) opens the capture window at the harness level,
    // the executed predicate is observable and demonstrably NOT the authorized scope's. This is
    // what keeps a self-scoping control arm honest and a scoped one caught, without any
    // individual mirror having to opt in.
    $liveCapture = new LiveToolCapture;
    $capture = new ConnectionPredicateCapture($liveCapture);
    app(Dispatcher::class)->listen(QueryExecuted::class, $capture);

    preparedOrders();

    $wrapped = new UnguardedCapturingTool(
        new UnguardedSearchOrders(new SearchOrders),
        'orders.search',
        $liveCapture,
        $capture,
    );
    $orders = json_decode((string) $wrapped->handle(new Request([], 'control-call-1')), true, flags: JSON_THROW_ON_ERROR)['orders'];

    expect(array_column($orders, 'id'))->toContain(1001)
        ->and($liveCapture->predicates())->toHaveCount(1)
        ->and($liveCapture->predicates()[0]->capability)->toBe('orders.search')
        ->and($liveCapture->predicates()[0]->digest)->not->toBe(PredicateDigest::for(searchScopeSql(), [72]));

    $observation = new Observation(disposition: null, executed: true, predicates: $liveCapture->predicates());
    expect(Assertions::executedPredicateNotScopedAs('orders.search', PredicateDigest::for(searchScopeSql(), [72]))->evaluate($observation)->passed)
        ->toBeTrue();
});
