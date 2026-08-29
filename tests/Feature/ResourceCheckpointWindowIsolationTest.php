<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\ExecutionWindow;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\ConnectionPredicateCapture;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\PredicateDigest;
use Fissible\Verdict\Evaluation\ResourceCheckpointCapture;
use Fissible\Verdict\Evaluation\ResourceDigest;
use Fissible\Verdict\Evidence\CanonicalJson;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Targets\ResourceProjection;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;

/**
 * The resource checkpoint must not be visible to the instrument beside it (#392).
 *
 * `ConnectionPredicateCapture` opens its window "around exactly the executor invocation, so
 * Verdict's own store traffic … runs outside it by construction and can never satisfy the presence
 * assertion." The checkpoint broke that: it ran inside the window, and a check-to-use digest exists
 * precisely so an application can READ the resource — so its identity resolver and its projector
 * are expected to query. Those queries were then attributed to the capability as executed
 * predicates, contaminating the rung the window exists to measure.
 *
 * Two assertions were affected in opposite directions. `executedPredicateShapeIsDeclared` is
 * universally quantified, so one projector query outside the declared shapes failed a case on the
 * instrument's own traffic. `executedPredicateObserved` is existential, so projector queries alone
 * satisfied it — an executor that never touched the database still "observed a predicate".
 *
 * WHY THESE TESTS DRIVE THE MANAGER. Exercising `ConnectionPredicateCapture::around()` directly
 * would pass while the checkpoint still runs inside the real window, because the test would be
 * choosing the nesting itself. Every test here goes through `VerdictManager::runBound()` with the
 * capture bound as the `ExecutionWindow`, which is the wiring that had the defect.
 */
function checkpointIsolationConnection(): Connection
{
    $connection = app(DatabaseManager::class)->connection();

    $connection->getSchemaBuilder()->dropIfExists('checkpoint_orders');
    $connection->getSchemaBuilder()->create('checkpoint_orders', function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('customer_id')->nullable();
        $table->string('item')->nullable();
    });
    $connection->insert('insert into checkpoint_orders (id, customer_id, item) values (?, ?, ?)', [1, 72, 'mouse']);

    return $connection;
}

function checkpointIsolationEnvelope(string $capability): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal($capability, ['order_id' => 1]),
        new ActionContext('customer-72'),
    );
}

/** Arms the predicate window and the resource checkpoint on one sink, the way a harness does. */
function checkpointIsolationSink(): LiveToolCapture
{
    $sink = new LiveToolCapture;
    $capture = new ConnectionPredicateCapture($sink);

    app(Dispatcher::class)->listen(QueryExecuted::class, $capture);
    app()->instance(ExecutionWindow::class, $capture);
    app()->instance(ResourceCheckpointCapture::class, new ResourceCheckpointCapture($sink, 'order-row'));

    return $sink;
}

/**
 * A policy whose identity resolver READS THE DATABASE, and a projection whose projector does too.
 * Both are application closures, and reading the resource is the entire point of a check-to-use
 * digest — so this is the ordinary shape, not an exotic one.
 */
function checkpointIsolationCapability(string $name, Connection $connection, callable $executor): Capability
{
    return Capability::usingPolicy(
        name: $name,
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): int => 1,
    )->executionTarget(ExecutionTargetPolicy::refresh(
        name: $name.'-target',
        identityUsing: function (ActionEnvelope $envelope, int $target) use ($connection): array {
            $row = $connection->selectOne('select id from checkpoint_orders where id = ?', [$target]);

            return ['resource_type' => 'order', 'resource_id' => $row->id ?? 0];
        },
        refreshUsing: fn (ActionEnvelope $envelope, int $target): int => $target,
    ))->resourceProjection(ResourceProjection::declared(
        'checkpoint-order/v1',
        function (ActionEnvelope $envelope, int $target) use ($connection): array {
            $row = $connection->selectOne('select item from checkpoint_orders where id = ?', [$target]);

            return ['item' => $row->item ?? null];
        },
    ))->executeUsing($executor);
}

function permitCheckpointIsolation(): void
{
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
}

it('attributes no predicate to a capability whose only queries were the checkpoint\'s', function (): void {
    // The existential half. The executor touches no database at all, so an honest instrument
    // reports that — and `executedPredicateObserved` must fail, which is what makes a digest-less
    // execution a failing case rather than a silent one.
    permitCheckpointIsolation();
    $connection = checkpointIsolationConnection();
    $sink = checkpointIsolationSink();

    app(VerdictManager::class)->capability(checkpointIsolationCapability(
        'orders.silent',
        $connection,
        fn (AuthorizedAction $action): string => 'executed without touching the database',
    ));

    $result = app(VerdictManager::class)->runBound(checkpointIsolationEnvelope('orders.silent'));

    expect($result->executed)->toBeTrue()
        // The checkpoint really ran — otherwise this test would pass by measuring nothing.
        ->and($sink->resources())->toHaveCount(1)
        ->and($sink->predicates())->toBe([]);

    $observation = new Observation(disposition: null, executed: true, predicates: $sink->predicates());

    expect(Assertions::executedPredicateObserved('orders.silent')->evaluate($observation)->passed)->toBeFalse();
});

it('leaves a declared-shape comparison holding when the checkpoint queries beside it', function (): void {
    // The universal half. `executedPredicateShapeIsDeclared` requires EVERY predicate attributed to
    // the capability to normalize to a declared shape, so a single checkpoint query — which no
    // pack manifest would ever declare — failed the case on the instrument's own traffic.
    permitCheckpointIsolation();
    $connection = checkpointIsolationConnection();
    $sink = checkpointIsolationSink();

    app(VerdictManager::class)->capability(checkpointIsolationCapability(
        'orders.declared',
        $connection,
        function (AuthorizedAction $action) use ($connection): string {
            $connection->select('select id, customer_id, item from checkpoint_orders where customer_id = ?', [72]);

            return 'executed';
        },
    ));

    $result = app(VerdictManager::class)->runBound(checkpointIsolationEnvelope('orders.declared'));

    expect($result->executed)->toBeTrue()
        ->and($sink->resources())->toHaveCount(1)
        // Exactly the executor's statement, and only it.
        ->and($sink->predicates())->toHaveCount(1)
        ->and($sink->predicates()[0]->capability)->toBe('orders.declared')
        ->and($sink->predicates()[0]->digest)
        ->toBe(PredicateDigest::for('select id, customer_id, item from checkpoint_orders where customer_id = ?', [72]));

    $observation = new Observation(disposition: null, executed: true, predicates: $sink->predicates());

    expect(Assertions::executedPredicateShapeIsDeclared('orders.declared', [
        'select id, customer_id, item from checkpoint_orders where customer_id = ?',
    ])->evaluate($observation)->passed)->toBeTrue();
});

it('keeps a nested capability\'s checkpoint out of the enclosing capability\'s window', function (): void {
    // Moving the checkpoint out of its OWN window is not sufficient: an inner capability executed
    // from inside an outer executor still runs while the outer frame is open, and each statement
    // belongs to the innermost OPEN frame. Without suppression the inner checkpoint's queries
    // simply change owner — from the inner capability to the outer one — and the contamination
    // survives under a different name.
    permitCheckpointIsolation();
    $connection = checkpointIsolationConnection();
    $sink = checkpointIsolationSink();

    $verdict = app(VerdictManager::class);

    $verdict->capability(checkpointIsolationCapability(
        'orders.inner',
        $connection,
        fn (AuthorizedAction $action): string => 'inner executed without touching the database',
    ));

    $verdict->capability(checkpointIsolationCapability(
        'orders.outer',
        $connection,
        function (AuthorizedAction $action) use ($connection, $verdict): string {
            $connection->select('select id from checkpoint_orders where customer_id = ?', [72]);

            $inner = $verdict->runBound(checkpointIsolationEnvelope('orders.inner'));

            return $inner->executed ? 'outer executed' : 'inner refused';
        },
    ));

    $result = $verdict->runBound(checkpointIsolationEnvelope('orders.outer'));

    expect($result->executed)->toBeTrue()
        // Both checkpoints ran, so both had queries that could have leaked.
        ->and($sink->resources())->toHaveCount(2);

    // The outer executor's single statement is the only predicate anywhere: neither checkpoint's
    // identity or projection reads were attributed to either capability.
    expect($sink->predicates())->toHaveCount(1)
        ->and($sink->predicates()[0]->capability)->toBe('orders.outer')
        ->and($sink->predicates()[0]->digest)
        ->toBe(PredicateDigest::for('select id from checkpoint_orders where customer_id = ?', [72]));

    $observation = new Observation(disposition: null, executed: true, predicates: $sink->predicates());

    expect(Assertions::executedPredicateObserved('orders.inner')->evaluate($observation)->passed)->toBeFalse();
});

it('still captures the executor after a checkpoint that threw', function (): void {
    // Whatever excludes the checkpoint's reads has to be released on the failure path too. A flag
    // left set would silently swallow the executor's real statements from then on — the instrument
    // reporting an absence it caused. The capture is fail-open by design, so a throwing projector
    // is an ordinary case, not an exotic one.
    //
    // Note what this test does NOT discriminate. An exclusion that restores without `finally`
    // behaves identically today, because `ResourceCheckpointCapture::capture()` catches Throwable
    // around everything that can throw — so nothing escapes an exclusion wrapped around it, and the
    // two shapes are indistinguishable from outside. The restoration requirement is therefore a
    // structural one: it holds only while that fail-open boundary encloses every risky call, and a
    // future throw added outside it would need this test to grow a companion.
    permitCheckpointIsolation();
    $connection = checkpointIsolationConnection();
    $sink = checkpointIsolationSink();

    app(VerdictManager::class)->capability(
        Capability::usingPolicy('orders.throwing', 'view', fn (ActionEnvelope $envelope): int => 1)
            ->executionTarget(acceptTestSnapshot('checkpoint-throwing-snapshot'))
            ->resourceProjection(ResourceProjection::declared(
                'checkpoint-order/v1',
                function (ActionEnvelope $envelope, int $target) use ($connection): array {
                    $connection->selectOne('select item from checkpoint_orders where id = ?', [$target]);

                    throw new RuntimeException('the projector could not describe the resource');
                },
            ))
            ->executeUsing(function (AuthorizedAction $action) use ($connection): string {
                $connection->select('select id from checkpoint_orders where customer_id = ?', [72]);

                return 'executed';
            }),
    );

    $result = app(VerdictManager::class)->runBound(checkpointIsolationEnvelope('orders.throwing'));

    expect($result->executed)->toBeTrue()
        // Unmeasured, as the checkpoint's fail-open rule requires...
        ->and($sink->resources())->toBe([])
        // ...and the executor's statement was still observed, so the exclusion was released.
        // Asserted by DIGEST, not by count: one leaked projector query plus one suppressed
        // executor query is also "one predicate for this capability", and would be the exact
        // failure this test exists to catch.
        ->and($sink->predicates())->toHaveCount(1)
        ->and($sink->predicates()[0]->capability)->toBe('orders.throwing')
        ->and($sink->predicates()[0]->digest)
        ->toBe(PredicateDigest::for('select id from checkpoint_orders where customer_id = ?', [72]));
});

it('digests the resource as it stood before the executor ran', function (): void {
    // Ordering, pinned behaviourally. A fix that merely stopped the checkpoint's queries being
    // observed while moving the checkpoint AFTER the executor would satisfy every test above and
    // silently destroy what a check-to-use digest means: the value at the moment of use. The
    // executor changes the row it was measured on, and the digest must predate that.
    permitCheckpointIsolation();
    $connection = checkpointIsolationConnection();
    $sink = checkpointIsolationSink();

    app(VerdictManager::class)->capability(checkpointIsolationCapability(
        'orders.mutating',
        $connection,
        function (AuthorizedAction $action) use ($connection): string {
            $connection->update('update checkpoint_orders set item = ? where id = ?', ['swapped', 1]);

            return 'executed';
        },
    ));

    $result = app(VerdictManager::class)->runBound(checkpointIsolationEnvelope('orders.mutating'));

    $before = ResourceDigest::SCHEME.':'.hash('sha256', CanonicalJson::encode(['item' => 'mouse'], 'resource-projection'));

    expect($result->executed)->toBeTrue()
        ->and($sink->resources())->toHaveCount(1)
        ->and($sink->resources()[0]->digest)->toBe($before);

    // And the row really did move, so the assertion above is a fact about ordering rather than
    // about an update that never happened.
    expect($connection->selectOne('select item from checkpoint_orders where id = ?', [1])->item)->toBe('swapped');
});

it('captures an executor statement that looks exactly like the checkpoint\'s', function (): void {
    // An exclusion keyed on what the SQL LOOKS LIKE would pass every test above, because the
    // checkpoint's statements are distinct from the executors' in all of them. It would also be
    // wrong in the one way that matters: a real executor is free to read the same row the same way,
    // and hiding its statement is the instrument reporting an absence it invented. Exclusion has to
    // be scoped by WHEN a statement runs, not by what it says.
    permitCheckpointIsolation();
    $connection = checkpointIsolationConnection();
    $sink = checkpointIsolationSink();

    app(VerdictManager::class)->capability(checkpointIsolationCapability(
        'orders.same-shape',
        $connection,
        function (AuthorizedAction $action) use ($connection): string {
            // Byte-identical to the declared projector's statement, bindings included.
            $connection->selectOne('select item from checkpoint_orders where id = ?', [1]);

            return 'executed';
        },
    ));

    $result = app(VerdictManager::class)->runBound(checkpointIsolationEnvelope('orders.same-shape'));

    expect($result->executed)->toBeTrue()
        ->and($sink->resources())->toHaveCount(1)
        ->and($sink->predicates())->toHaveCount(1)
        ->and($sink->predicates()[0]->digest)
        ->toBe(PredicateDigest::for('select item from checkpoint_orders where id = ?', [1]));
});

it('keeps the checkpoint excluded after a run nested inside it returns', function (): void {
    // Whatever excludes the checkpoint's reads has to nest. A boolean flag rather than a depth
    // count would be cleared by the inner run's own exclusion ending, and the outer projector's
    // remaining statements would leak from that point on. So the projector below queries, starts a
    // whole bound run, and queries again — the second query is the one a flag would lose.
    permitCheckpointIsolation();
    $connection = checkpointIsolationConnection();
    $sink = checkpointIsolationSink();

    $verdict = app(VerdictManager::class);

    $verdict->capability(checkpointIsolationCapability(
        'orders.nested-inner',
        $connection,
        function (AuthorizedAction $action) use ($connection): string {
            $connection->select('select id from checkpoint_orders where id = ?', [1]);

            return 'inner executed';
        },
    ));

    $verdict->capability(
        Capability::usingPolicy('orders.nesting-projector', 'view', fn (ActionEnvelope $envelope): int => 1)
            ->executionTarget(acceptTestSnapshot('checkpoint-nesting-snapshot'))
            ->resourceProjection(ResourceProjection::declared(
                'checkpoint-order/v1',
                function (ActionEnvelope $envelope, int $target) use ($connection, $verdict): array {
                    $connection->selectOne('select item from checkpoint_orders where id = ?', [$target]);

                    // An application projector that resolves its value through another capability.
                    $verdict->runBound(checkpointIsolationEnvelope('orders.nested-inner'));

                    // After that run has entered and left its own exclusion, this one is still the
                    // instrument's traffic.
                    $row = $connection->selectOne('select customer_id from checkpoint_orders where id = ?', [$target]);

                    return ['customer_id' => $row->customer_id ?? null];
                },
            ))
            ->executeUsing(function (AuthorizedAction $action) use ($connection): string {
                $connection->select('select id from checkpoint_orders where customer_id = ?', [72]);

                return 'executed';
            }),
    );

    $result = $verdict->runBound(checkpointIsolationEnvelope('orders.nesting-projector'));

    expect($result->executed)->toBeTrue()
        ->and($sink->resources())->toHaveCount(2);

    // Only the outer executor's statement. Everything the projector caused — its own two queries,
    // and the entire nested run it started — is instrument traffic.
    expect($sink->predicates())->toHaveCount(1)
        ->and($sink->predicates()[0]->capability)->toBe('orders.nesting-projector')
        ->and($sink->predicates()[0]->digest)
        ->toBe(PredicateDigest::for('select id from checkpoint_orders where customer_id = ?', [72]));
});
