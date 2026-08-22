<?php

declare(strict_types=1);

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\PredicateDigest;
use Fissible\Verdict\Evaluation\PredicateObservation;

/**
 * The observed side of the filtered-permit comparison (#251): each statement the connection
 * listener captures becomes a `PredicateObservation` — the scheme-tagged digest plus the
 * normalized statement, never the binding values. Presence is itself an assertion: per the
 * decided design, an execution with no captured digest is a failing case, because silence from
 * the instrument is indistinguishable from nothing having run.
 */

// --- The value object ---------------------------------------------------------------------------

it('derives digest and normalized sql from the executed query', function (): void {
    $observation = PredicateObservation::fromQuery(
        "select *\n  from \"orders\" where \"customer_id\" = ?",
        [7],
    );

    expect($observation->digest)
        ->toBe(PredicateDigest::for('select * from "orders" where "customer_id" = ?', [7]))
        ->and($observation->sql)->toBe('select * from "orders" where "customer_id" = ?');
});

it('rejects a digest that does not carry the scheme tag', function (): void {
    new PredicateObservation(hash('sha256', 'bare'), 'select 1');
})->throws(InvalidArgumentException::class);

it('rejects a scheme-tagged digest whose hash is malformed', function (): void {
    new PredicateObservation(PredicateDigest::SCHEME.':short', 'select 1');
})->throws(InvalidArgumentException::class);

it('rejects an empty statement', function (): void {
    new PredicateObservation(PredicateDigest::for('select 1', []), '  ');
})->throws(InvalidArgumentException::class);

// --- Observation carries predicates as an assertion-only list -----------------------------------

it('accepts predicate observations on the observation', function (): void {
    $predicate = PredicateObservation::fromQuery('select * from "orders" where "customer_id" = ?', [7]);

    $observation = new Observation(
        disposition: Disposition::Permit,
        executed: true,
        predicates: [$predicate],
    );

    expect($observation->predicates)->toBe([$predicate]);
});

it('rejects a predicates list holding anything but PredicateObservation', function (): void {
    new Observation(
        disposition: Disposition::Permit,
        executed: true,
        predicates: ['select * from "orders"'],
    );
})->throws(InvalidArgumentException::class);

// --- The presence assertion ---------------------------------------------------------------------

it('passes executedPredicateObserved when at least one predicate was captured', function (): void {
    $observation = new Observation(
        disposition: Disposition::Permit,
        executed: true,
        predicates: [PredicateObservation::fromQuery('select * from "orders" where "customer_id" = ?', [7])],
    );

    $result = Assertions::executedPredicateObserved()->evaluate($observation);

    expect($result->passed)->toBeTrue();
});

it('fails executedPredicateObserved when the execution produced no captured digest', function (): void {
    // The decided design (#251 round 4): a path that produces no digest is a failing case, not a
    // silent pass — silence from the instrument is indistinguishable from nothing having run.
    $observation = new Observation(
        disposition: Disposition::Permit,
        executed: true,
        predicates: [],
    );

    $result = Assertions::executedPredicateObserved()->evaluate($observation);

    expect($result->passed)->toBeFalse();
});
