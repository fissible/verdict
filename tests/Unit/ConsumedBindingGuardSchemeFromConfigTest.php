<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\ConsumedBindingGuardScheme;
use Fissible\Verdict\Exceptions\InvalidConsumedBindingGuardConfig;

// Hardening of the keyed-digest opt-in (ADR 0039): ConsumedBindingGuardScheme::fromConfig() is the
// one place the operator-supplied guard config becomes a scheme, and it FAILS CLOSED. The previous
// provider resolver coerced key versions to strings but gated active_key on is_string(), so an
// integer active_key silently selected keyless — the exact exposure keyed mode removes, and permanent
// because keyed mode is not retroactive. fromConfig() now honours a scalar active_key, refuses a
// misconfiguration rather than degrading to keyless, and rejects a secret too weak to be a key.

const FC_TC = 'call-1';
const FC_CAP = 'orders.cancel';
const FC_FP = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const FC_SECRET = 'a-secret-of-at-least-thirty-two-characters';    // 42 chars
const FC_SECRET_2 = 'another-secret-of-adequate-key-length-here'; // 42 chars

it('returns null (keyless) when no config block is set', function (): void {
    expect(ConsumedBindingGuardScheme::fromConfig(null))->toBeNull();
});

it('builds a keyless scheme from the shipped default (no active key, no keys)', function (): void {
    $scheme = ConsumedBindingGuardScheme::fromConfig(['active_key' => null, 'keys' => []]);

    expect($scheme)->toBeInstanceOf(ConsumedBindingGuardScheme::class)
        ->and($scheme->candidates(FC_TC, FC_CAP, FC_FP))->toBe([ConsumedBindingGuard::digest(FC_TC, FC_CAP, FC_FP)])
        ->and($scheme->active(FC_TC, FC_CAP, FC_FP)->algorithm)->toBeNull();
});

it('builds a keyed scheme from a valid config', function (): void {
    $scheme = ConsumedBindingGuardScheme::fromConfig(['active_key' => 'v1', 'keys' => ['v1' => FC_SECRET]]);

    $guard = $scheme->active(FC_TC, FC_CAP, FC_FP);
    expect($guard->digest)->toBe(ConsumedBindingGuard::keyed(FC_TC, FC_CAP, FC_FP, FC_SECRET))
        ->and($guard->keyVersion)->toBe('v1');
});

it('honours a scalar (integer) active key rather than silently falling back to keyless', function (): void {
    // The fail-open: version 1 in `keys` is coerced to '1', but the old resolver left active_key an
    // integer and is_string() rejected it -> keyless. fromConfig coerces both, so this is keyed.
    $scheme = ConsumedBindingGuardScheme::fromConfig(['active_key' => 1, 'keys' => [1 => FC_SECRET]]);

    $guard = $scheme->active(FC_TC, FC_CAP, FC_FP);
    expect($guard->keyVersion)->toBe('1')
        ->and($guard->algorithm)->toBe(ConsumedBindingGuard::ALGORITHM_KEYED)
        ->and($guard->digest)->toBe(ConsumedBindingGuard::keyed(FC_TC, FC_CAP, FC_FP, FC_SECRET));
});

it('probes every retained key regardless of which is active, with a mix of scalar versions', function (): void {
    $scheme = ConsumedBindingGuardScheme::fromConfig(['active_key' => 'v2', 'keys' => [1 => FC_SECRET, 'v2' => FC_SECRET_2]]);

    expect($scheme->candidates(FC_TC, FC_CAP, FC_FP))->toBe([
        ConsumedBindingGuard::digest(FC_TC, FC_CAP, FC_FP),
        ConsumedBindingGuard::keyed(FC_TC, FC_CAP, FC_FP, FC_SECRET),
        ConsumedBindingGuard::keyed(FC_TC, FC_CAP, FC_FP, FC_SECRET_2),
    ]);
});

it('fails closed when the active key is not among the retained keys', function (): void {
    expect(fn () => ConsumedBindingGuardScheme::fromConfig(['active_key' => 'v9', 'keys' => ['v1' => FC_SECRET]]))
        ->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('fails closed when active_key is not a scalar', function (): void {
    expect(fn () => ConsumedBindingGuardScheme::fromConfig(['active_key' => ['v1'], 'keys' => ['v1' => FC_SECRET]]))
        ->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('fails closed when the config block is present but not an array', function (): void {
    expect(fn () => ConsumedBindingGuardScheme::fromConfig('v1=secret'))
        ->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('fails closed when keys is present but not an array', function (): void {
    expect(fn () => ConsumedBindingGuardScheme::fromConfig(['active_key' => null, 'keys' => 'v1=secret']))
        ->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('fails closed on a non-string secret, naming the version', function (): void {
    // The old is_scalar coercion turned true into the secret '1' — a hardened-looking, trivially
    // brute-forceable key. A non-string secret is a misconfiguration, not a key.
    try {
        ConsumedBindingGuardScheme::fromConfig(['active_key' => 'v1', 'keys' => ['v1' => true]]);
        expect(false)->toBeTrue('expected InvalidConsumedBindingGuardConfig');
    } catch (InvalidConsumedBindingGuardConfig $exception) {
        expect($exception->getMessage())->toContain('v1');
    }
});

it('fails closed on a secret below the minimum length, naming the version', function (int|string $weak): void {
    try {
        ConsumedBindingGuardScheme::fromConfig(['active_key' => 'v1', 'keys' => ['v1' => $weak]]);
        expect(false)->toBeTrue('expected InvalidConsumedBindingGuardConfig');
    } catch (InvalidConsumedBindingGuardConfig $exception) {
        expect($exception->getMessage())->toContain('v1');
    }
})->with([
    'empty' => '',
    'short' => 'too-short',
    'one below the floor' => str_repeat('a', 31),
]);

it('fails closed on a weak or non-string RETAINED (non-active) secret, naming that version', function (string|bool $weak): void {
    // Retained keys feed candidates() (the replay-detection probe), so every secret must be strong,
    // not only the active one. Here v1 is active and valid; the weak secret is the retained v2. An
    // implementation that validates only the active key's secret would pass the active-key cases but
    // ship a still-brute-forceable retained probe — this closes that hole.
    try {
        ConsumedBindingGuardScheme::fromConfig(['active_key' => 'v1', 'keys' => ['v1' => FC_SECRET, 'v2' => $weak]]);
        expect(false)->toBeTrue('expected InvalidConsumedBindingGuardConfig');
    } catch (InvalidConsumedBindingGuardConfig $exception) {
        expect($exception->getMessage())->toContain('v2');
    }
})->with([
    'short retained' => 'too-short',
    'non-string retained' => true,
]);

it('validates retained secrets even under a keyless (no active version) config', function (): void {
    // Retained-only mode (keyed probing, keyless writes) must still reject a weak retained secret.
    expect(fn () => ConsumedBindingGuardScheme::fromConfig(['active_key' => null, 'keys' => ['v1' => 'too-short']]))
        ->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('accepts a secret exactly at the minimum length', function (): void {
    $scheme = ConsumedBindingGuardScheme::fromConfig(['active_key' => 'v1', 'keys' => ['v1' => str_repeat('a', 32)]]);

    expect($scheme->active(FC_TC, FC_CAP, FC_FP)->keyVersion)->toBe('v1');
});
