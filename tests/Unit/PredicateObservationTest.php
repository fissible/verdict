<?php

declare(strict_types=1);

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\PredicateDigest;
use Fissible\Verdict\Evaluation\PredicateObservation;
use Fissible\Verdict\Evaluation\ToolObservation;

/**
 * The observed side of the filtered-permit comparison (#251): each statement the execution window
 * captures becomes a `PredicateObservation` — attributed to the capability and argument
 * fingerprint whose executor ran it, carrying the scheme-tagged digest plus the normalized
 * statement, never the binding values. Presence is itself an assertion: per the decided design,
 * an execution with no captured digest is a failing case, because silence from the instrument is
 * indistinguishable from nothing having run.
 */
function anObservedPredicate(string $capability = 'orders.search', string $sql = 'select * from "orders" where "customer_id" = ?'): PredicateObservation
{
    return PredicateObservation::fromQuery($sql, [7], $capability, str_repeat('a', 64));
}

// --- The value object ---------------------------------------------------------------------------

it('derives digest and normalized sql from the executed query, attributed to its execution', function (): void {
    $observation = PredicateObservation::fromQuery(
        "select *\n  from \"orders\" where \"customer_id\" = ?",
        [7],
        'orders.search',
        str_repeat('a', 64),
    );

    expect($observation->digest)
        ->toBe(PredicateDigest::for('select * from "orders" where "customer_id" = ?', [7]))
        ->and($observation->sql)->toBe('select * from "orders" where "customer_id" = ?')
        ->and($observation->capability)->toBe('orders.search')
        ->and($observation->argumentFingerprint)->toBe(str_repeat('a', 64));
});

it('rejects an empty capability', function (): void {
    PredicateObservation::fromQuery('select 1', [], '  ', str_repeat('a', 64));
})->throws(InvalidArgumentException::class);

it('rejects a malformed argument fingerprint', function (): void {
    PredicateObservation::fromQuery('select 1', [], 'orders.search', 'not-a-fingerprint');
})->throws(InvalidArgumentException::class);

it('rejects a digest that does not carry the scheme tag', function (): void {
    new PredicateObservation('orders.search', str_repeat('a', 64), hash('sha256', 'bare'), 'select 1');
})->throws(InvalidArgumentException::class);

it('rejects a scheme-tagged digest whose hash is malformed', function (): void {
    new PredicateObservation('orders.search', str_repeat('a', 64), PredicateDigest::SCHEME.':short', 'select 1');
})->throws(InvalidArgumentException::class);

it('rejects an empty statement', function (): void {
    new PredicateObservation('orders.search', str_repeat('a', 64), PredicateDigest::for('select 1', []), '  ');
})->throws(InvalidArgumentException::class);

// --- forNormalized: the digest without a second normalization pass ------------------------------

it('digests an already-normalized statement identically to the normalizing path', function (): void {
    $raw = "select *\n  from \"orders\" where \"customer_id\" = ?";

    expect(PredicateDigest::forNormalized(PredicateDigest::normalize($raw), [7]))
        ->toBe(PredicateDigest::for($raw, [7]));
});

// --- Observation carries predicates as an assertion-only list -----------------------------------

it('accepts predicate observations on the observation', function (): void {
    $predicate = anObservedPredicate();

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
        predicates: [anObservedPredicate()],
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

it('scopes executedPredicateObserved to a capability when one is named', function (): void {
    // A run that calls two tools must not let one capability's captured statements satisfy the
    // presence requirement for the other — the attribution exists so digests can be paired with
    // the authorization that produced them. Both capabilities were attempted here, so the scoped
    // miss is a measured FAIL rather than the CapabilityNotAttempted unmeasured outcome.
    $observation = new Observation(
        disposition: Disposition::Permit,
        executed: true,
        toolCalls: [
            new ToolObservation('orders.read', str_repeat('a', 64), Disposition::Permit, true),
            new ToolObservation('orders.search', str_repeat('b', 64), Disposition::Permit, true),
        ],
        predicates: [anObservedPredicate(capability: 'orders.read')],
    );

    expect(Assertions::executedPredicateObserved('orders.read')->evaluate($observation)->passed)->toBeTrue()
        ->and(Assertions::executedPredicateObserved('orders.search')->evaluate($observation)->passed)->toBeFalse();
});
