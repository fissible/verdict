<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Evidence\CanonicalJson;

/**
 * A scheme-tagged digest over an executed SQL predicate — statement text plus bindings.
 *
 * The filtered-permit case asserts digest(executed predicate) == digest(authorized scope), which
 * makes this normalizer security-bearing: a normalizer one clause too forgiving maps an
 * authorization-relevant widening onto the same digest, and the comparison silently passes. This
 * layer has no upstream oracle to inherit correctness from, so it ships with its own adversarial
 * specification — the widening-mutation suite in `PredicateDigestTest` — and every normalization
 * rule it applies must be individually justified against that suite before it is added.
 *
 * **Asymmetry policy: prefer false failure over false pass.** A harmless recompilation difference
 * that fails the comparison is annoying and visible; an over-normalization that erases a widening
 * clause is invisible and defeats the check. Version 1 therefore absorbs exactly one variation:
 * whitespace outside quoted regions. Everything else is deliberately not absorbed, and each refusal
 * is pinned by a test so the pressure to normalize aggressively meets a written policy instead of an
 * ad-hoc patch:
 *
 * - **Binding order** — even a semantically neutral reordering fails the comparison. Absorbing it
 *   requires proving the reordering cannot also reorder which condition each value feeds.
 * - **Alias choice** — absorbing it requires alias-aware parsing; a rename rule applied textually
 *   can merge distinct references.
 * - **Appended order-by / limit** — a limit changes which rows come back; an order-by can, combined
 *   with a limit, change the result set. Neither is provably harmless at this layer.
 * - **Binding value types** — `7` and `'7'` compare differently on some engines and collations.
 * - **Keyword or identifier case** — identifier case-sensitivity is engine- and collation-dependent.
 *
 * **Quoted regions are copied verbatim.** Single quotes (literals), double quotes and backticks
 * (identifiers) open a region that runs to the matching close quote; a doubled quote (`''`) and a
 * backslash-escaped character both stay inside the region. Where escape dialects disagree, the rule
 * over-extends the region rather than ending it early: over-extension only under-collapses (a
 * visible false failure), while early termination collapses whitespace that was inside a literal
 * (an invisible false pass).
 *
 * **Scheme tag.** Following the `RecordDigest` precedent: the normalization revision and hash are
 * carried in the value itself, so a future normalizer revision is a new scheme — additive — rather
 * than a silent re-identity of digests already recorded in evidence.
 */
final class PredicateDigest
{
    /**
     * A change to the normalization rules, the canonicalization, or the hash requires a new scheme,
     * not a silent recomputation.
     */
    public const string SCHEME = 'sqlpredicate-v1-canonicaljson-sha256';

    /**
     * Positional bindings enter as a list, where order is identity — a reordered list is a
     * different predicate. Named bindings enter keyed by placeholder, where key order is not
     * identity; `CanonicalJson` sorts object keys.
     *
     * @param  list<bool|float|int|string|null>|array<string, bool|float|int|string|null>  $bindings
     */
    public static function for(string $sql, array $bindings): string
    {
        return self::forNormalized(self::normalize($sql), $bindings);
    }

    /**
     * Whether a string is a digest under this scheme — the one shape check, owned here beside the
     * scheme it validates, so consumers cannot drift apart on what a valid digest looks like.
     */
    public static function isDigest(string $value): bool
    {
        return preg_match('/^'.preg_quote(self::SCHEME, '/').':[a-f0-9]{64}\z/', $value) === 1;
    }

    /**
     * The digest when the caller already holds the normalized statement — one normalization pass
     * for a capture that also carries the normalized text. The caller's contract is strict: pass
     * anything but the output of {@see normalize()} and the result is a digest `for()` can never
     * produce, which the comparison will refuse — a false failure, per the asymmetry policy, never
     * a false pass.
     *
     * @param  list<bool|float|int|string|null>|array<string, bool|float|int|string|null>  $bindings
     */
    public static function forNormalized(string $normalizedSql, array $bindings): string
    {
        return self::SCHEME.':'.hash('sha256', CanonicalJson::encode(
            ['bindings' => $bindings, 'sql' => $normalizedSql],
            'predicate digest',
        ));
    }

    /**
     * The normalized statement: whitespace runs collapsed to a single space outside quoted regions,
     * leading and trailing whitespace dropped, quoted regions byte-exact.
     *
     * Public because the digest alone is not the only consumer: the where-tree cross-check compares
     * `normalize(wire_sql)` against `normalize(tree_sql)`, and a human diagnosing a digest mismatch
     * needs the exact form the digest was computed over.
     */
    public static function normalize(string $sql): string
    {
        $normalized = '';
        $length = strlen($sql);
        $position = 0;
        $pendingSpace = false;

        while ($position < $length) {
            $character = $sql[$position];

            if ($character === "'" || $character === '"' || $character === '`') {
                $start = $position;
                $position++;

                while ($position < $length) {
                    if ($sql[$position] === '\\') {
                        $position += 2;

                        continue;
                    }

                    if ($sql[$position] === $character) {
                        if ($position + 1 < $length && $sql[$position + 1] === $character) {
                            $position += 2;

                            continue;
                        }

                        $position++;

                        break;
                    }

                    $position++;
                }

                if ($pendingSpace && $normalized !== '') {
                    $normalized .= ' ';
                }
                $pendingSpace = false;
                $normalized .= substr($sql, $start, min($position, $length) - $start);

                continue;
            }

            if (ctype_space($character)) {
                $pendingSpace = true;
                $position++;

                continue;
            }

            if ($pendingSpace && $normalized !== '') {
                $normalized .= ' ';
            }
            $pendingSpace = false;
            $normalized .= $character;
            $position++;
        }

        return $normalized;
    }
}
