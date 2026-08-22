<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\PredicateDigest;

/**
 * The widening-mutation suite over the predicate normalizer (#260).
 *
 * The filtered-permit case (#251) asserts digest(executed predicate) == digest(authorized scope),
 * which makes this normalizer the security-bearing component: a normalizer one clause too forgiving
 * maps an authorization-relevant widening onto the same digest and the comparison silently passes.
 * This layer has no upstream oracle to inherit correctness from, so this class is its specification.
 *
 * The property: two predicates that differ in an authorization-relevant way must never normalize to
 * the same digest. Policy: prefer false failure over false pass — every variation the normalizer
 * does NOT absorb is pinned here as deliberate, so pressure to normalize aggressively meets a
 * failing test instead of an ad-hoc patch.
 */

// --- Scheme tag ---------------------------------------------------------------------------------

it('produces a scheme-tagged digest so a future normalizer revision is additive, not a re-identity', function (): void {
    $digest = PredicateDigest::for('select * from "orders" where "customer_id" = ?', [7]);

    expect($digest)
        ->toStartWith(PredicateDigest::SCHEME.':')
        ->and(substr($digest, strlen(PredicateDigest::SCHEME) + 1))->toMatch('/^[0-9a-f]{64}$/');
});

// --- What v1 absorbs: whitespace outside quoted regions, nothing else ---------------------------

it('absorbs whitespace differences outside quoted regions', function (): void {
    $compact = PredicateDigest::for('select * from "orders" where "customer_id" = ? and "status" = ?', [7, 'open']);
    $spread = PredicateDigest::for(
        "select *\n  from \"orders\"\n where \"customer_id\" = ?\n   and \"status\" = ?",
        [7, 'open'],
    );

    expect($spread)->toBe($compact);
});

it('preserves whitespace inside a single-quoted literal', function (): void {
    expect(PredicateDigest::for("select * from \"orders\" where \"note\" = 'a b'", []))
        ->not->toBe(PredicateDigest::for("select * from \"orders\" where \"note\" = 'a  b'", []));
});

it('preserves whitespace inside a double-quoted identifier', function (): void {
    expect(PredicateDigest::for('select "my col" from "orders"', []))
        ->not->toBe(PredicateDigest::for('select "my  col" from "orders"', []));
});

it('preserves whitespace inside a backtick-quoted identifier', function (): void {
    expect(PredicateDigest::for('select `my col` from `orders`', []))
        ->not->toBe(PredicateDigest::for('select `my  col` from `orders`', []));
});

it('does not let an escaped quote terminate the literal early', function (): void {
    // If '' ended the literal, the two spaces after it would sit outside the quoted region and be
    // collapsed — silently merging two different literals into one digest.
    expect(PredicateDigest::for("select * from \"orders\" where \"note\" = 'it''s  here'", []))
        ->not->toBe(PredicateDigest::for("select * from \"orders\" where \"note\" = 'it''s here'", []));
});

it('does not let a backslash-escaped quote terminate the literal early', function (): void {
    // MySQL honors backslash escapes in string literals. If \' ended the literal, the spaces after
    // it would be collapsed — the false-pass direction. Over-extending a region only under-collapses,
    // which is the failure the policy prefers.
    expect(PredicateDigest::for("select * from \"orders\" where \"note\" = 'it\\'s  here'", []))
        ->not->toBe(PredicateDigest::for("select * from \"orders\" where \"note\" = 'it\\'s here'", []));
});

// --- The widening-mutation floor: each mutation must change the digest --------------------------

it('changes the digest when a widening mutation is applied', function (string $sql, array $bindings, string $widenedSql, array $widenedBindings): void {
    expect(PredicateDigest::for($widenedSql, $widenedBindings))
        ->not->toBe(PredicateDigest::for($sql, $bindings));
})->with([
    'append a disjunct at the same nesting level' => [
        'select * from "orders" where "customer_id" = ? and "status" = ?',
        [7, 'open'],
        'select * from "orders" where "customer_id" = ? and "status" = ? or "customer_id" = ?',
        [7, 'open', 9],
    ],
    'drop a join condition' => [
        'select * from "orders" inner join "customers" on "customers"."id" = "orders"."customer_id" and "customers"."tenant_id" = ? where "orders"."status" = ?',
        [3, 'open'],
        'select * from "orders" inner join "customers" on "customers"."id" = "orders"."customer_id" where "orders"."status" = ?',
        ['open'],
    ],
    'relax an equality to a range' => [
        'select * from "orders" where "customer_id" = ?',
        [7],
        'select * from "orders" where "customer_id" >= ?',
        [7],
    ],
    'remove a nested group' => [
        'select * from "orders" where "customer_id" = ? and ("status" = ? or "status" = ?)',
        [7, 'open', 'pending'],
        'select * from "orders" where "customer_id" = ?',
        [7],
    ],
]);

it('changes the digest when only a binding value widens', function (): void {
    $sql = 'select * from "orders" where "customer_id" = ?';

    expect(PredicateDigest::for($sql, [8]))->not->toBe(PredicateDigest::for($sql, [7]));
});

// --- False failures pinned as deliberate: variations v1 refuses to absorb -----------------------

it('does not absorb binding order, even when the reordering is semantically neutral', function (): void {
    // "status" = 'open' AND "customer_id" = 7 both ways — same predicate, recompiled. v1 fails the
    // comparison rather than risk a normalization rule aggressive enough to erase a widening.
    expect(PredicateDigest::for('select * from "orders" where "status" = ? and "customer_id" = ?', ['open', 7]))
        ->not->toBe(PredicateDigest::for('select * from "orders" where "customer_id" = ? and "status" = ?', [7, 'open']));
});

it('does not absorb alias choice', function (): void {
    expect(PredicateDigest::for('select * from "orders" as "o" where "o"."customer_id" = ?', [7]))
        ->not->toBe(PredicateDigest::for('select * from "orders" as "x" where "x"."customer_id" = ?', [7]));
});

it('does not absorb an appended order-by or limit', function (): void {
    $base = PredicateDigest::for('select * from "orders" where "customer_id" = ?', [7]);

    expect(PredicateDigest::for('select * from "orders" where "customer_id" = ? order by "id" asc', [7]))
        ->not->toBe($base)
        ->and(PredicateDigest::for('select * from "orders" where "customer_id" = ? limit 10', [7]))
        ->not->toBe($base);
});

it('does not absorb binding type differences', function (): void {
    $sql = 'select * from "orders" where "customer_id" = ?';

    expect(PredicateDigest::for($sql, ['7']))->not->toBe(PredicateDigest::for($sql, [7]));
});

// --- Structural separation of sql and bindings --------------------------------------------------

it('never collides a clause moved from the statement into a binding value', function (): void {
    // The digest is computed over a structured {sql, bindings} pair, not a concatenation — SQL text
    // appearing as a binding string must not produce the digest of that text inside the statement.
    expect(PredicateDigest::for('select * from "orders" where "note" = ?', ['x order by "id"']))
        ->not->toBe(PredicateDigest::for('select * from "orders" where "note" = ? order by "id"', ['x']));
});

// --- The normalized form itself (public because #251 cross-checks normalize(wire) == normalize(tree)) ---

it('normalizes to a trimmed, single-spaced statement outside quoted regions', function (): void {
    expect(PredicateDigest::normalize("  select *\n  from \"orders\"\twhere \"note\" = 'a  b'  "))
        ->toBe('select * from "orders" where "note" = \'a  b\'');
});
