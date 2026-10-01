<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ConsumedBindingGuardScheme;
use Fissible\Verdict\Exceptions\InvalidConsumedBindingGuardConfig;

// #537 (codex retro-review findings 2.2 + 1.1): ConsumedBindingGuardScheme::fromConfig() rejects two
// versions that share the SAME secret string, but HMAC-SHA256 key normalization means DISTINCT
// strings can be the same effective key: any key < the 64-byte block plus trailing NUL(s) aliases
// (zero-padding), and a >64-byte key is pre-hashed to its raw SHA-256. Two versions then derive
// identical digests for every binding, so a probe match cannot attribute a version and a v1 guard
// validates as v2. Separately a key_version longer than the storage column (string() => varchar(255))
// is accepted by config but silently truncated on write, corrupting the permanent metadata. fromConfig
// must fail closed on BOTH — across ALL retained pairs, not just the active key — at config time.

const GSKC_SECRET_COLUMN_LIMIT = 255; // key_version column: Schema string() default

/** Do two secret strings resolve to the SAME effective HMAC-SHA256 key? (what the rejections target) */
function gskcSameEffectiveKey(string $a, string $b): bool
{
    return hash_hmac('sha256', 'gskc-probe', $a) === hash_hmac('sha256', 'gskc-probe', $b);
}

function gskcSecret(string $char, int $len): string
{
    return str_repeat($char, $len); // >= ConsumedBindingGuardScheme::MINIMUM_SECRET_LENGTH when len >= 32
}

it('sanity: fixes the effective-key relationships the accept/reject cases rely on', function (): void {
    // Aliases (same effective key):
    expect(gskcSameEffectiveKey(gskcSecret('a', 40), gskcSecret('a', 40)."\0"))->toBeTrue()             // < block + NUL
        ->and(gskcSameEffectiveKey(gskcSecret('a', 63), gskcSecret('a', 63)."\0"))->toBeTrue()          // aliases exactly at 64
        ->and(gskcSameEffectiveKey(gskcSecret('x', 100), hash('sha256', gskcSecret('x', 100), true)))->toBeTrue() // >64 pre-hash
        ->and(gskcSameEffectiveKey(gskcSecret('x', 100), hash('sha256', gskcSecret('x', 100), true)."\0"))->toBeTrue(); // combined
    // Genuinely distinct (must NOT be rejected):
    expect(gskcSameEffectiveKey(gskcSecret('a', 40), gskcSecret('b', 40)))->toBeFalse()
        ->and(gskcSameEffectiveKey(gskcSecret('a', 64), hash('sha256', gskcSecret('a', 64), true)))->toBeFalse()  // 64 used as-is, not pre-hashed
        ->and(gskcSameEffectiveKey(gskcSecret('a', 64), gskcSecret('a', 64)."\0"))->toBeFalse()                   // 65 IS pre-hashed
        ->and(gskcSameEffectiveKey(gskcSecret('a', 20)."\0".gskcSecret('a', 20), gskcSecret('a', 41)))->toBeFalse(); // interior NUL matters
});

// --- Rejections: colliding effective keys across retained versions ---------------------------------

dataset('colliding key pairs', [
    'trailing NUL below the block' => [gskcSecret('a', 40), gskcSecret('a', 40)."\0"],
    'trailing NUL aliasing exactly at 64' => [gskcSecret('a', 63), gskcSecret('a', 63)."\0"],
    'long secret vs its raw SHA-256 (pre-hash)' => [gskcSecret('x', 100), hash('sha256', gskcSecret('x', 100), true)],
    'long secret vs raw SHA-256 plus NUL (combined)' => [gskcSecret('x', 100), hash('sha256', gskcSecret('x', 100), true)."\0"],
]);

it('rejects two adjacent versions whose effective HMAC keys collide', function (string $a, string $b): void {
    expect(fn () => ConsumedBindingGuardScheme::fromConfig([
        'keys' => ['v1' => $a, 'v2' => $b],
        'active_key' => 'v1',
    ]))->toThrow(InvalidConsumedBindingGuardConfig::class);
})->with('colliding key pairs');

it('rejects a collision between two NON-ADJACENT RETAINED versions while a distinct third key is active', function (): void {
    expect(fn () => ConsumedBindingGuardScheme::fromConfig([
        'keys' => [
            'v1' => gskcSecret('a', 40),
            'v2' => gskcSecret('b', 40),        // active, distinct
            'v3' => gskcSecret('a', 40)."\0",   // collides with v1
        ],
        'active_key' => 'v2',
    ]))->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('rejects a retained-version collision even with active_key => null (keyless-write, keyed-probe)', function (): void {
    expect(fn () => ConsumedBindingGuardScheme::fromConfig([
        'keys' => [
            'v1' => gskcSecret('x', 100),
            'v2' => hash('sha256', gskcSecret('x', 100), true), // collides with v1 via pre-hash
        ],
    ]))->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('still rejects two versions that share the identical secret string (existing contract preserved)', function (): void {
    expect(fn () => ConsumedBindingGuardScheme::fromConfig([
        'keys' => ['v1' => gskcSecret('a', 40), 'v2' => gskcSecret('a', 40)],
        'active_key' => 'v1',
    ]))->toThrow(InvalidConsumedBindingGuardConfig::class);
});

// --- Rejections: over-length key_version -----------------------------------------------------------

it('rejects a key_version longer than the storage column, as the active key', function (): void {
    $long = str_repeat('v', GSKC_SECRET_COLUMN_LIMIT + 1);

    expect(fn () => ConsumedBindingGuardScheme::fromConfig([
        'keys' => [$long => gskcSecret('s', 40)],
        'active_key' => $long,
    ]))->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('rejects an over-length RETAINED key_version while a shorter version is active', function (): void {
    $long = str_repeat('v', GSKC_SECRET_COLUMN_LIMIT + 1);

    expect(fn () => ConsumedBindingGuardScheme::fromConfig([
        'keys' => ['v1' => gskcSecret('a', 40), $long => gskcSecret('b', 40)],
        'active_key' => 'v1',
    ]))->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('rejects an over-length retained key_version with active_key => null', function (): void {
    $long = str_repeat('v', GSKC_SECRET_COLUMN_LIMIT + 1);

    expect(fn () => ConsumedBindingGuardScheme::fromConfig([
        'keys' => [$long => gskcSecret('a', 40)],
    ]))->toThrow(InvalidConsumedBindingGuardConfig::class);
});

// --- Acceptances: must NOT over-reject -------------------------------------------------------------

it('accepts genuinely distinct keys and derives distinct candidate digests through the scheme', function (): void {
    $scheme = ConsumedBindingGuardScheme::fromConfig([
        'keys' => ['v1' => gskcSecret('a', 40), 'v2' => gskcSecret('b', 40)],
        'active_key' => 'v2',
    ]);

    expect($scheme)->toBeInstanceOf(ConsumedBindingGuardScheme::class);

    // Through the scheme (not a bypassing keyed() call): every candidate digest is distinct.
    $candidates = $scheme->candidates('t', 'c', 'b');
    expect($candidates)->toHaveCount(3) // keyless + v1 + v2
        ->and(array_unique($candidates))->toHaveCount(3)
        ->and($scheme->active('t', 'c', 'b')->keyVersion)->toBe('v2');
});

it('accepts distinct keys at the HMAC block boundary (64-byte used as-is, not pre-hashed)', function (): void {
    // 64-byte vs its raw SHA-256 (distinct: 64 is NOT pre-hashed); and 64-byte vs 64+NUL (65 IS pre-hashed).
    $scheme = ConsumedBindingGuardScheme::fromConfig([
        'keys' => [
            'v1' => gskcSecret('a', 64),
            'v2' => hash('sha256', gskcSecret('a', 64), true),
            'v3' => gskcSecret('a', 64)."\0",
        ],
        'active_key' => 'v1',
    ]);

    $candidates = $scheme->candidates('t', 'c', 'b');
    expect($candidates)->toHaveCount(4) // keyless + v1 + v2 + v3, none dropped
        ->and(array_unique($candidates))->toHaveCount(4) // all distinct → rightly allowed
        ->and($scheme->active('t', 'c', 'b')->keyVersion)->toBe('v1');
});

it('accepts an interior-NUL key distinct from its NUL-free sibling', function (): void {
    $scheme = ConsumedBindingGuardScheme::fromConfig([
        'keys' => [
            'v1' => gskcSecret('a', 20)."\0".gskcSecret('a', 20),
            'v2' => gskcSecret('a', 41),
        ],
        'active_key' => 'v1',
    ]);

    $candidates = $scheme->candidates('t', 'c', 'b');
    expect($candidates)->toHaveCount(3) // keyless + v1 + v2
        ->and(array_unique($candidates))->toHaveCount(3)
        ->and($scheme->active('t', 'c', 'b')->keyVersion)->toBe('v1');
});

it('does not cap secret length as if it were version length (long secret, short version accepted)', function (): void {
    $scheme = ConsumedBindingGuardScheme::fromConfig([
        'keys' => ['s' => str_repeat('x', GSKC_SECRET_COLUMN_LIMIT + 45)], // 300-byte SECRET, short version
        'active_key' => 's',
    ]);

    $candidates = $scheme->candidates('t', 'c', 'b');
    expect($candidates)->toHaveCount(2) // keyless + the single retained key, none dropped
        ->and(array_unique($candidates))->toHaveCount(2)
        ->and($scheme->active('t', 'c', 'b')->keyVersion)->toBe('s');
});

it('accepts a key_version exactly at the column limit and preserves it verbatim', function (): void {
    $atLimit = str_repeat('v', GSKC_SECRET_COLUMN_LIMIT);

    $scheme = ConsumedBindingGuardScheme::fromConfig([
        'keys' => [$atLimit => gskcSecret('a', 40)],
        'active_key' => $atLimit,
    ]);

    expect($scheme->active('t', 'c', 'b')->keyVersion)->toBe($atLimit);
});
