<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\ExecutionWindow;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\ConnectionPredicateCapture;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\PredicateDigest;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;

/**
 * The observed side of the filtered-permit comparison is captured at the connection (#251 round 4):
 * global scopes, soft-delete constraints, raw fragments, and driver rewrites all enter below any
 * builder-tree inspection, and everything that reaches the database goes through a connection. The
 * capture is armed by the core `ExecutionWindow` seam, so a captured statement is the executor's —
 * never Verdict's own store bookkeeping — and it digests bindings in **prepared** form, the form
 * the database actually sees, because `QueryExecuted` reports raw bindings (a `DateTimeImmutable`
 * would otherwise crash canonicalization from inside the event dispatch, after the statement
 * already ran). These tests run real statements through the real connection and its event
 * dispatcher, never synthetic events.
 */

/** @return array{ConnectionPredicateCapture, Connection} */
function listeningCapture(?LiveToolCapture $sink = null): array
{
    $capture = new ConnectionPredicateCapture($sink);
    app(Dispatcher::class)->listen(QueryExecuted::class, $capture);

    $connection = app(DatabaseManager::class)->connection();
    $connection->getSchemaBuilder()->dropIfExists('capture_orders');
    // The schema builder renders driver-correct column types: a raw "datetime" column is not a
    // PostgreSQL type, and the DateTime-binding proof below must run on every engine in the matrix.
    $connection->getSchemaBuilder()->create('capture_orders', function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('customer_id')->nullable();
        $table->dateTime('created_at')->nullable();
    });

    return [$capture, $connection];
}

function captureEnvelope(string $capability, array $arguments): ActionEnvelope
{
    return ActionEnvelope::wrap(new ActionProposal($capability, $arguments), new ActionContext('customer-72'));
}

it('captures every statement executed inside the window, attributed to its envelope, and nothing outside it', function (): void {
    [$capture, $connection] = listeningCapture();

    $connection->select('select * from capture_orders where customer_id = ?', [99]);

    $capture->around(captureEnvelope('orders.search', ['customer_email' => 'a@example.com']), function () use ($connection): void {
        $connection->select('select * from capture_orders where customer_id = ?', [7]);
    });

    $connection->select('select * from capture_orders where customer_id = ?', [42]);

    expect($capture->observations())->toHaveCount(1)
        ->and($capture->observations()[0]->digest)
        ->toBe(PredicateDigest::for('select * from capture_orders where customer_id = ?', [7]))
        ->and($capture->observations()[0]->sql)
        ->toBe('select * from capture_orders where customer_id = ?')
        ->and($capture->observations()[0]->capability)->toBe('orders.search')
        ->and($capture->observations()[0]->argumentFingerprint)
        ->toBe(ArgumentFingerprint::make(['customer_email' => 'a@example.com']));
});

it('digests bindings in prepared form, so object bindings neither crash the dispatch nor diverge from what the database saw', function (): void {
    [$capture, $connection] = listeningCapture();

    $since = new DateTimeImmutable('2026-08-01 00:00:00');

    $capture->around(captureEnvelope('orders.search', []), function () use ($connection, $since): void {
        $connection->select('select * from capture_orders where created_at > ? and id > ?', [$since, true]);
    });

    expect($capture->observations())->toHaveCount(1)
        ->and($capture->observations()[0]->digest)
        ->toBe(PredicateDigest::for(
            'select * from capture_orders where created_at > ? and id > ?',
            $connection->prepareBindings([$since, true]),
        ));
});

it('captures at the connection, not per statement kind: writes inside the window are observed too', function (): void {
    [$capture, $connection] = listeningCapture();

    $capture->around(captureEnvelope('orders.create', []), function () use ($connection): void {
        $connection->insert('insert into capture_orders (id, customer_id) values (?, ?)', [1, 7]);
    });

    expect($capture->observations())->toHaveCount(1)
        ->and($capture->observations()[0]->digest)
        ->toBe(PredicateDigest::for('insert into capture_orders (id, customer_id) values (?, ?)', [1, 7]));
});

it('ignores pretended statements, which never executed', function (): void {
    [$capture, $connection] = listeningCapture();

    $capture->around(captureEnvelope('orders.search', []), function () use ($connection): void {
        $connection->pretend(function () use ($connection): void {
            $connection->select('select * from capture_orders where customer_id = ?', [7]);
        });
    });

    expect($capture->observations())->toBe([]);
});

it('returns the window callable result', function (): void {
    [$capture, $connection] = listeningCapture();

    $rows = $capture->around(
        captureEnvelope('orders.search', []),
        fn (): array => $connection->select('select * from capture_orders where customer_id = ?', [7]),
    );

    expect($rows)->toBe([]);
});

it('keeps the statements that ran before an executor failure, attributed, and the exception propagates', function (): void {
    [$capture, $connection] = listeningCapture();

    try {
        $capture->around(captureEnvelope('orders.search', []), function () use ($connection): void {
            $connection->select('select * from capture_orders where customer_id = ?', [7]);

            throw new RuntimeException('executor failed');
        });

        $this->fail('The window exception did not propagate.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('executor failed');
    }

    $connection->select('select * from capture_orders where customer_id = ?', [42]);

    expect($capture->observations())->toHaveCount(1)
        ->and($capture->observations()[0]->capability)->toBe('orders.search');
});

it('attributes nested windows to their own envelopes', function (): void {
    // A sub-agent's capability executing inside another's executor opens an inner window. Each
    // statement belongs to the innermost open window; the outer window keeps collecting after the
    // inner one closes, so neither disarms nor absorbs the other.
    [$capture, $connection] = listeningCapture();

    $capture->around(captureEnvelope('orders.outer', []), function () use ($capture, $connection): void {
        $connection->select('select * from capture_orders where customer_id = ?', [1]);

        $capture->around(captureEnvelope('orders.inner', []), function () use ($connection): void {
            $connection->select('select * from capture_orders where customer_id = ?', [2]);
        });

        $connection->select('select * from capture_orders where customer_id = ?', [3]);
    });

    $byCapability = [];
    foreach ($capture->observations() as $observation) {
        $byCapability[$observation->capability][] = $observation->digest;
    }

    expect($byCapability['orders.inner'])
        ->toBe([PredicateDigest::for('select * from capture_orders where customer_id = ?', [2])])
        ->and($byCapability['orders.outer'])->toBe([
            PredicateDigest::for('select * from capture_orders where customer_id = ?', [1]),
            PredicateDigest::for('select * from capture_orders where customer_id = ?', [3]),
        ]);
});

it('records into the live tool capture when constructed with one as its sink', function (): void {
    $sink = new LiveToolCapture;
    [$capture, $connection] = listeningCapture($sink);

    $capture->around(captureEnvelope('orders.search', []), function () use ($connection): void {
        $connection->select('select * from capture_orders where customer_id = ?', [7]);
    });

    expect($sink->predicates())->toHaveCount(1)
        ->and($sink->predicates()[0]->capability)->toBe('orders.search')
        ->and($capture->observations())->toBe([]);
});

it('accumulates across windows until reset', function (): void {
    [$capture, $connection] = listeningCapture();

    $capture->around(captureEnvelope('orders.search', []), fn (): array => $connection->select('select * from capture_orders where customer_id = ?', [7]));
    $capture->around(captureEnvelope('orders.search', []), fn (): array => $connection->select('select * from capture_orders where customer_id = ?', [8]));

    expect($capture->observations())->toHaveCount(2);

    $capture->reset();

    expect($capture->observations())->toBe([]);
});

/**
 * The review round on #264 exposed that every prior test ran under the Null/InMemory stores — the
 * configuration in which the two disqualifying defects (raw-binding crash, presence satisfied by
 * harness SQL) are invisible. This test runs the whole pipeline under the real Database stores:
 * evidence recording and execution-claim traffic bind DateTimeImmutable values and write inside
 * the same request, and the case passes only if none of that crashes the capture and none of it is
 * attributed to the executor.
 */
it('captures only the executor statements under the real database stores', function (): void {
    $connection = app(DatabaseManager::class)->connection();

    $tables = [verdictTable('evidence'), verdictTable('execution_claims'), 'capture_orders'];
    foreach ($tables as $table) {
        $connection->getSchemaBuilder()->dropIfExists($table);
    }

    foreach ([
        'create_verdict_evidence_table',
        'add_provenance_to_verdict_evidence_table',
        'add_invocation_id_to_verdict_evidence_table',
        'add_tool_kind_to_verdict_evidence_table',
        'add_configuration_fingerprint_to_verdict_evidence_table',
        'add_actor_and_subject_fingerprints_to_verdict_evidence_table',
        'add_target_source_to_verdict_evidence_table',
        'add_tool_description_fingerprints_to_verdict_evidence_table',
        'add_record_identity_to_verdict_evidence_table',
        'add_intent_id_to_verdict_evidence_table',
        'create_verdict_execution_claims_table',
    ] as $name) {
        (require __DIR__.'/../../database/migrations/'.$name.'.php.stub')->up();
    }

    $connection->getSchemaBuilder()->create('capture_orders', function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('customer_id')->nullable();
    });
    $connection->insert('insert into capture_orders (id, customer_id) values (?, ?)', [1, 72]);

    // The base TestCase pins the InMemory claim store; this test exists precisely to run under
    // the real database stores, whose DateTimeImmutable bindings crashed the first capture design.
    config()->set('verdict.execution_claims.store', DatabaseExecutionClaimStore::class);
    $this->app->instance(EvidenceRecorder::class, new DatabaseEvidenceRecorder($connection));
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });

    $sink = new LiveToolCapture;
    $capture = new ConnectionPredicateCapture($sink);
    app(Dispatcher::class)->listen(QueryExecuted::class, $capture);
    $this->app->instance(ExecutionWindow::class, $capture);

    $verdict = app(VerdictManager::class);
    $verdict->capability(
        Capability::usingPolicy(
            name: 'orders.search',
            ability: 'cancel',
            resolveTarget: fn (ActionEnvelope $envelope): string => 'customer-orders',
        )->executionTarget(acceptTestSnapshot('capture-database-stores-snapshot'))
            ->atMostOnce(ExecutionClaimPolicy::named(
                'order-search',
                fn (ActionEnvelope $envelope, string $target): array => ['customer' => $envelope->context->actor],
            ))
            ->executeUsing(fn (AuthorizedAction $action): string => json_encode(
                $connection->select('select * from capture_orders where customer_id = ?', [72]),
                JSON_THROW_ON_ERROR,
            )),
    );

    $result = $verdict->runBound(captureEnvelope('orders.search', ['customer_email' => 'a@example.com']));

    expect($result->executed)->toBeTrue()
        // Evidence rows and a completed claim really were written in this request...
        ->and($connection->table(verdictTable('evidence'))->count())->toBeGreaterThan(0)
        ->and($connection->table(verdictTable('execution_claims'))->count())->toBe(1)
        // ...and none of that traffic was captured: the window held exactly the executor's query.
        ->and($sink->predicates())->toHaveCount(1)
        ->and($sink->predicates()[0]->digest)
        ->toBe(PredicateDigest::for('select * from capture_orders where customer_id = ?', [72]))
        ->and($sink->predicates()[0]->capability)->toBe('orders.search');

    $presence = new Observation(disposition: null, executed: true, predicates: $sink->predicates());
    expect(Assertions::executedPredicateObserved('orders.search')->evaluate($presence)->passed)->toBeTrue();

    foreach ($tables as $table) {
        $connection->getSchemaBuilder()->dropIfExists($table);
    }
});
