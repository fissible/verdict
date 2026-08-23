<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

/**
 * One statement the execution window observed: the scheme-tagged {@see PredicateDigest} plus the
 * normalized statement text, attributed to the capability and argument fingerprint whose executor
 * ran it.
 *
 * Attribution is load-bearing, not decorative: the filtered-permit comparison pairs an executed
 * digest with the authorization that produced it, and a run that calls two tools would otherwise
 * offer no way to say whose statements these were.
 *
 * Binding values are deliberately not carried. They enter the digest — that is what makes the
 * digest a predicate identity rather than a shape identity — but a captured binding can be a
 * customer identifier or a protected value, and observations flow into assertion failures and
 * debug output. The normalized statement keeps the placeholder form, so what travels is query
 * structure plus an irreversible content digest, matching the evidence rule that raw values never
 * ride along where a fingerprint will do.
 *
 * **Bindings must be in prepared form** — the form the database actually sees, after
 * `Connection::prepareBindings()` has formatted `DateTimeInterface` values and cast booleans.
 * `QueryExecuted` reports the raw pre-preparation bindings, which both crash canonicalization on
 * object values and would put the two sides of the future equality comparison in different binding
 * forms. {@see ConnectionPredicateCapture} prepares before constructing; anything else building
 * these must do the same.
 *
 * Assertion-only, like `ChallengeObservation`: never projected into reports or baselines.
 */
final readonly class PredicateObservation
{
    public function __construct(
        public string $capability,
        public string $argumentFingerprint,
        public string $digest,
        public string $sql,
    ) {
        if (trim($this->capability) === '') {
            throw new InvalidArgumentException('A predicate observation must name a capability.');
        }

        if (preg_match('/^[a-f0-9]{64}\z/', $this->argumentFingerprint) !== 1) {
            throw new InvalidArgumentException('A predicate observation requires a SHA-256 argument fingerprint.');
        }

        if (! PredicateDigest::isDigest($this->digest)) {
            throw new InvalidArgumentException(
                'A predicate observation requires a '.PredicateDigest::SCHEME.'-tagged digest.',
            );
        }

        if (trim($this->sql) === '') {
            throw new InvalidArgumentException('A predicate observation requires a non-empty statement.');
        }
    }

    /**
     * @param  list<bool|float|int|string|null>|array<string, bool|float|int|string|null>  $bindings
     *                                                                                                in prepared form — see the class docblock.
     */
    public static function fromQuery(string $sql, array $bindings, string $capability, string $argumentFingerprint): self
    {
        $normalized = PredicateDigest::normalize($sql);

        return new self(
            capability: $capability,
            argumentFingerprint: $argumentFingerprint,
            digest: PredicateDigest::forNormalized($normalized, $bindings),
            sql: $normalized,
        );
    }
}
