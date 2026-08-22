<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\ConnectionPredicateCapture;
use Fissible\Verdict\Evaluation\PredicateDigest;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;

/**
 * The observed side of the filtered-permit comparison is captured at the connection (#251 round 4):
 * global scopes, soft-delete constraints, raw fragments, and driver rewrites all enter below any
 * builder-tree inspection, and everything that reaches the database goes through a connection — so
 * escaping this listener takes effort, and a path nobody remembered to instrument produces a
 * captured digest anyway rather than silence. These tests run real statements through the real
 * connection and its event dispatcher, never synthetic events.
 */

/** @return array{ConnectionPredicateCapture, Connection} */
function listeningCapture(): array
{
    $capture = new ConnectionPredicateCapture;
    app(Dispatcher::class)->listen(QueryExecuted::class, $capture);

    $connection = app(DatabaseManager::class)->connection();
    $connection->getSchemaBuilder()->dropIfExists('capture_orders');
    $connection->statement('create table "capture_orders" ("id" integer primary key, "customer_id" integer)');

    return [$capture, $connection];
}

it('captures every statement executed inside the window, and nothing outside it', function (): void {
    [$capture, $connection] = listeningCapture();

    $connection->select('select * from "capture_orders" where "customer_id" = ?', [99]);

    $capture->window(function () use ($connection): void {
        $connection->select('select * from "capture_orders" where "customer_id" = ?', [7]);
    });

    $connection->select('select * from "capture_orders" where "customer_id" = ?', [42]);

    expect($capture->observations())->toHaveCount(1)
        ->and($capture->observations()[0]->digest)
        ->toBe(PredicateDigest::for('select * from "capture_orders" where "customer_id" = ?', [7]))
        ->and($capture->observations()[0]->sql)
        ->toBe('select * from "capture_orders" where "customer_id" = ?');
});

it('captures at the connection, not per statement kind: writes inside the window are observed too', function (): void {
    [$capture, $connection] = listeningCapture();

    $capture->window(function () use ($connection): void {
        $connection->insert('insert into "capture_orders" ("id", "customer_id") values (?, ?)', [1, 7]);
    });

    expect($capture->observations())->toHaveCount(1)
        ->and($capture->observations()[0]->digest)
        ->toBe(PredicateDigest::for('insert into "capture_orders" ("id", "customer_id") values (?, ?)', [1, 7]));
});

it('returns the window callable result', function (): void {
    [$capture, $connection] = listeningCapture();

    $rows = $capture->window(
        fn (): array => $connection->select('select * from "capture_orders" where "customer_id" = ?', [7]),
    );

    expect($rows)->toBe([]);
});

it('disarms when the window callable throws, and the exception propagates', function (): void {
    [$capture, $connection] = listeningCapture();

    try {
        $capture->window(function () use ($connection): void {
            $connection->select('select * from "capture_orders" where "customer_id" = ?', [7]);

            throw new RuntimeException('executor failed');
        });

        $this->fail('The window exception did not propagate.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('executor failed');
    }

    $connection->select('select * from "capture_orders" where "customer_id" = ?', [42]);

    expect($capture->observations())->toHaveCount(1);
});

it('drains observations: returns them and leaves the capture empty', function (): void {
    // The live decorator drains after each tool-call window so one call's statements are recorded
    // against that call exactly once, while the underlying listener registration stays process-long.
    [$capture, $connection] = listeningCapture();

    $capture->window(fn (): array => $connection->select('select * from "capture_orders" where "customer_id" = ?', [7]));

    $drained = $capture->drain();

    expect($drained)->toHaveCount(1)
        ->and($drained[0]->digest)
        ->toBe(PredicateDigest::for('select * from "capture_orders" where "customer_id" = ?', [7]))
        ->and($capture->observations())->toBe([]);
});

it('accumulates across windows until reset', function (): void {
    [$capture, $connection] = listeningCapture();

    $capture->window(fn (): array => $connection->select('select * from "capture_orders" where "customer_id" = ?', [7]));
    $capture->window(fn (): array => $connection->select('select * from "capture_orders" where "customer_id" = ?', [8]));

    expect($capture->observations())->toHaveCount(2);

    $capture->reset();

    expect($capture->observations())->toBe([]);
});
