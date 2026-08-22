<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

/**
 * One statement the connection listener observed during an execution: the scheme-tagged
 * {@see PredicateDigest} plus the normalized statement text.
 *
 * Binding values are deliberately not carried. They enter the digest — that is what makes the
 * digest a predicate identity rather than a shape identity — but a captured binding can be a
 * customer identifier or a protected value, and observations flow into assertion failures and
 * debug output. The normalized statement keeps the placeholder form, so what travels is query
 * structure plus an irreversible content digest, matching the evidence rule that raw values never
 * ride along where a fingerprint will do.
 *
 * Assertion-only, like `ChallengeObservation`: never projected into reports or baselines.
 */
final readonly class PredicateObservation
{
    public function __construct(
        public string $digest,
        public string $sql,
    ) {
        $scheme = preg_quote(PredicateDigest::SCHEME, '/');

        if (preg_match('/^'.$scheme.':[a-f0-9]{64}\z/', $this->digest) !== 1) {
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
     */
    public static function fromQuery(string $sql, array $bindings): self
    {
        return new self(
            digest: PredicateDigest::for($sql, $bindings),
            sql: PredicateDigest::normalize($sql),
        );
    }
}
