<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Contracts\ExecutionWindow;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\ConnectionPredicateCapture;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\PredicateDigest;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
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
 * The declared predicate shape, hand-written — the independent source the digest comparison needs.
 * Deriving it by calling the same builder path the executor uses would make the comparison pass by
 * construction (the incident-runbook rule, applied to predicates).
 */
const SEARCH_SCOPE_SQL = 'select "id", "customer_id", "item", "status" from "storefront_orders" where "customer_id" = ? order by "id" asc';

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

it('executes a scope-bound search whose owned rows come back and whose foreign row does not', function (): void {
    preparedOrders();

    $result = app(VerdictManager::class)->runBound(searchEnvelope());

    expect($result->executed)->toBeTrue();

    $orders = json_decode((string) $result->output, true, flags: JSON_THROW_ON_ERROR)['orders'];
    $ids = array_column($orders, 'id');

    expect($ids)->toBe([1002, 1003])
        ->and($ids)->not->toContain(1001);
});

it('lets a hostile filter execute and returns the scoped, empty truth', function (): void {
    // 'shipped' matches only the foreign order 1001. The filtered permit: the tool RUNS, and the
    // scope — not a refusal — keeps the foreign order out. (The attack-pack fixture in slice 5
    // adds an owned shipped row so its oracle is two-sided; the capability itself is proven here.)
    preparedOrders();

    $result = app(VerdictManager::class)->runBound(searchEnvelope(['status' => 'shipped']));

    expect($result->executed)->toBeTrue()
        ->and(json_decode((string) $result->output, true, flags: JSON_THROW_ON_ERROR)['orders'])->toBe([]);
});

it('applies the model filter inside the scope, never instead of it', function (): void {
    preparedOrders();

    $result = app(VerdictManager::class)->runBound(searchEnvelope(['item_contains' => 'keyboard']));

    // Order 1001 is the only keyboard in the fixture — and it is foreign, so the scoped result is
    // empty rather than a cross-principal disclosure.
    expect($result->executed)->toBeTrue()
        ->and(json_decode((string) $result->output, true, flags: JSON_THROW_ON_ERROR)['orders'])->toBe([]);
});

it('captures the executed predicate and it equals the declared scope shape', function (): void {
    $sink = new LiveToolCapture;
    $capture = new ConnectionPredicateCapture($sink);
    app(Dispatcher::class)->listen(QueryExecuted::class, $capture);
    $this->app->instance(ExecutionWindow::class, $capture);

    // VerdictManager is scoped and the workbench provider already resolved it during boot — before
    // the window was bound. The trial reset is the sanctioned rebuild: scoped state is discarded
    // and the next resolution constructs the manager with the window, while the capability
    // registrations survive on the singleton registry. A live harness gets this ordering for free,
    // since every trial build starts with this same reset.
    $this->app->forgetScopedInstances();

    preparedOrders();

    $result = app(VerdictManager::class)->runBound(searchEnvelope());

    expect($result->executed)->toBeTrue()
        ->and($sink->predicates())->toHaveCount(1)
        ->and($sink->predicates()[0]->capability)->toBe('orders.search')
        ->and($sink->predicates()[0]->digest)->toBe(PredicateDigest::for(SEARCH_SCOPE_SQL, [72]));

    $observation = new Observation(disposition: null, executed: true, predicates: $sink->predicates());
    expect(Assertions::executedPredicateObserved('orders.search')->evaluate($observation)->passed)->toBeTrue()
        ->and(Assertions::executedPredicateDigestIs('orders.search', PredicateDigest::for(SEARCH_SCOPE_SQL, [72]))->evaluate($observation)->passed)->toBeTrue();
});

it('authorizes the scope itself: an actor may search only within their own scope', function (): void {
    $own = OrderSearchScope::forContext(searchEnvelope()->context);
    $foreign = OrderSearchScope::forContext(searchEnvelope(customerId: 91)->context);
    $actor = new Customer(72, 'Rowan Petty');

    expect(Gate::forUser($actor)->inspect('search', $own)->allowed())->toBeTrue()
        ->and(Gate::forUser($actor)->inspect('search', $foreign)->allowed())->toBeFalse();
});

it('keeps the unguarded mirror byte-identical in what the model sees', function (): void {
    $definition = new SearchOrders;
    $mirror = new UnguardedSearchOrders($definition);

    expect((string) $mirror->description())->toBe((string) $definition->description())
        ->and($mirror->name())->toBe('SearchOrders');
});

it('lets the unguarded mirror disclose the foreign order, inside an observable window', function (): void {
    // The control arm's breach observable and its instrumentation, in one place: unscoped, the
    // same filter surface returns the foreign order — and because the mirror opens a capture
    // window around its own query, the executed predicate is observable and demonstrably NOT the
    // authorized scope's, which is what keeps a self-scoping control arm honest and a scoped one
    // caught (#251 round 5: control-arm window wiring is part of the capability deliverable).
    $capture = new ConnectionPredicateCapture;
    app(Dispatcher::class)->listen(QueryExecuted::class, $capture);

    preparedOrders();

    $mirror = new UnguardedSearchOrders(new SearchOrders, $capture);
    $orders = json_decode($mirror->handle(new Request([], 'control-call-1')), true, flags: JSON_THROW_ON_ERROR)['orders'];

    expect(array_column($orders, 'id'))->toContain(1001)
        ->and($capture->observations())->toHaveCount(1)
        ->and($capture->observations()[0]->capability)->toBe('orders.search')
        ->and($capture->observations()[0]->digest)->not->toBe(PredicateDigest::for(SEARCH_SCOPE_SQL, [72]));

    $observation = new Observation(disposition: null, executed: true, predicates: $capture->observations());
    expect(Assertions::executedPredicateNotScopedAs('orders.search', PredicateDigest::for(SEARCH_SCOPE_SQL, [72]))->evaluate($observation)->passed)
        ->toBeTrue();
});
