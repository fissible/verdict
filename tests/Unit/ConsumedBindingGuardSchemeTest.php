<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\ConsumedBindingGuardScheme;
use Fissible\Verdict\Approvals\DerivedGuard;
use Fissible\Verdict\Exceptions\MissingConsumedBindingGuardKey;

// Slice 10a of ADR 0039 (keyed digest opt-in, the resolver). ConsumedBindingGuardScheme turns the
// configured keys into (a) the candidate digests issue() must probe — the keyless digest ALWAYS,
// plus one per retained keyed version, so legacy keyless guards stay checkable after migration — and
// (b) the single guard consume() persists under the ACTIVE scheme. It FAILS CLOSED: a retained or
// active key whose secret is missing throws rather than let a check silently skip a candidate or a
// write mint an unverifiable guard. Pure logic: keys are constructor arguments here; reading them
// from config and wiring the scheme into issue()/consume() are later slices.

const CBGS_TC = 'call-1';
const CBGS_CAP = 'orders.cancel';
const CBGS_FP = 'fingerprint-abc';

function cbgsKeyless(): string
{
    return ConsumedBindingGuard::digest(CBGS_TC, CBGS_CAP, CBGS_FP);
}

it('probes only the keyless digest when no keys are configured', function (): void {
    $scheme = new ConsumedBindingGuardScheme;

    expect($scheme->candidates(CBGS_TC, CBGS_CAP, CBGS_FP))->toBe([cbgsKeyless()]);
});

it('derives a keyless guard for consume() when no active key is set', function (): void {
    $guard = (new ConsumedBindingGuardScheme)->active(CBGS_TC, CBGS_CAP, CBGS_FP);

    expect($guard)->toBeInstanceOf(DerivedGuard::class)
        ->and($guard->digest)->toBe(cbgsKeyless())
        ->and($guard->algorithm)->toBeNull()
        ->and($guard->keyVersion)->toBeNull();
});

it('keeps consume() keyless when keys are retained but no active version is set', function (): void {
    // Keyed mode is NOT retroactive: retained keys exist so issue() can still probe guards written
    // under them, but they must not make consume() start minting keyed guards. Only an explicit
    // active version selects the keyed write scheme — a convenience bug like
    // `activeKeyVersion ?? array_key_first($keys)` would silently break that guarantee.
    $guard = (new ConsumedBindingGuardScheme(['v1' => 'secret-1', 'v2' => 'secret-2']))->active(CBGS_TC, CBGS_CAP, CBGS_FP);

    expect($guard->digest)->toBe(cbgsKeyless())
        ->and($guard->algorithm)->toBeNull()
        ->and($guard->keyVersion)->toBeNull();
});

it('probes the keyless digest plus one candidate per retained keyed version', function (): void {
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1', 'v2' => 'secret-2']);

    $candidates = $scheme->candidates(CBGS_TC, CBGS_CAP, CBGS_FP);

    expect($candidates)->toBe([
        cbgsKeyless(),
        ConsumedBindingGuard::keyed(CBGS_TC, CBGS_CAP, CBGS_FP, 'secret-1'),
        ConsumedBindingGuard::keyed(CBGS_TC, CBGS_CAP, CBGS_FP, 'secret-2'),
    ])
        ->and($candidates)->toHaveCount(3)
        // Distinct: keyless is not an HMAC, and different secrets diverge.
        ->and(array_unique($candidates))->toHaveCount(3);
});

it('always keeps the keyless candidate so legacy keyless guards remain checkable after migration', function (): void {
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v1');

    expect($scheme->candidates(CBGS_TC, CBGS_CAP, CBGS_FP))->toContain(cbgsKeyless());
});

it('derives a keyed guard under the active version for consume()', function (): void {
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1', 'v2' => 'secret-2'], activeKeyVersion: 'v2');

    $guard = $scheme->active(CBGS_TC, CBGS_CAP, CBGS_FP);

    expect($guard->digest)->toBe(ConsumedBindingGuard::keyed(CBGS_TC, CBGS_CAP, CBGS_FP, 'secret-2'))
        ->and($guard->algorithm)->toBe(ConsumedBindingGuard::ALGORITHM_KEYED)
        ->and($guard->keyVersion)->toBe('v2');
});

it('does not double-probe the active version among the candidates', function (): void {
    // A naive impl that iterates the retained keys AND separately appends the active keyed candidate
    // yields [keyless, v1, v2, v2] — the active version probed twice. The candidate set is exactly
    // the keyless digest plus one per retained version, regardless of which is active.
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1', 'v2' => 'secret-2'], activeKeyVersion: 'v2');

    $candidates = $scheme->candidates(CBGS_TC, CBGS_CAP, CBGS_FP);

    expect($candidates)->toHaveCount(3) // keyless + v1 + v2, not + v2 again
        ->and(array_unique($candidates))->toHaveCount(3)
        ->and($candidates)->toContain($scheme->active(CBGS_TC, CBGS_CAP, CBGS_FP)->digest);
});

it('derives a guard whose digest is among the candidates it would probe, under every scheme', function (): void {
    // The decisive consistency invariant: what consume() writes is exactly what a later issue()
    // would find. If it broke, a consumed binding could be re-issued.
    $keyless = new ConsumedBindingGuardScheme;
    expect($keyless->candidates(CBGS_TC, CBGS_CAP, CBGS_FP))->toContain($keyless->active(CBGS_TC, CBGS_CAP, CBGS_FP)->digest);

    // Retained keys but no active version: active() is keyless, and the keyless candidate is present.
    $retainedNoActive = new ConsumedBindingGuardScheme(['v1' => 'secret-1']);
    expect($retainedNoActive->candidates(CBGS_TC, CBGS_CAP, CBGS_FP))->toContain($retainedNoActive->active(CBGS_TC, CBGS_CAP, CBGS_FP)->digest);

    $keyed = new ConsumedBindingGuardScheme(['v1' => 'secret-1', 'v2' => 'secret-2'], activeKeyVersion: 'v2');
    expect($keyed->candidates(CBGS_TC, CBGS_CAP, CBGS_FP))->toContain($keyed->active(CBGS_TC, CBGS_CAP, CBGS_FP)->digest);
});

it('rotates: a different active version derives a different guard digest', function (): void {
    $keys = ['v1' => 'secret-1', 'v2' => 'secret-2'];

    $onV1 = (new ConsumedBindingGuardScheme($keys, activeKeyVersion: 'v1'))->active(CBGS_TC, CBGS_CAP, CBGS_FP);
    $onV2 = (new ConsumedBindingGuardScheme($keys, activeKeyVersion: 'v2'))->active(CBGS_TC, CBGS_CAP, CBGS_FP);

    expect($onV1->digest)->not->toBe($onV2->digest)
        ->and($onV1->keyVersion)->toBe('v1')
        ->and($onV2->keyVersion)->toBe('v2');
});

it('fails closed when a retained key has no secret, naming the offending version', function (): void {
    // issue() cannot re-derive that candidate to check it, so it must refuse rather than skip it and
    // risk re-issuing a binding consumed under the missing key. The version is named so the wiring
    // slice can surface which key an operator must restore.
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1', 'v2' => '']);

    try {
        $scheme->candidates(CBGS_TC, CBGS_CAP, CBGS_FP);
        expect(false)->toBeTrue('expected a MissingConsumedBindingGuardKey to be thrown');
    } catch (MissingConsumedBindingGuardKey $exception) {
        expect($exception->keyVersion)->toBe('v2')
            ->and($exception->getMessage())->toContain('v2');
    }
});

it('fails closed when the active key is not among the configured keys', function (): void {
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v9');

    expect(fn (): DerivedGuard => $scheme->active(CBGS_TC, CBGS_CAP, CBGS_FP))
        ->toThrow(MissingConsumedBindingGuardKey::class);
});

it('fails closed when the active key is present but its secret is empty', function (): void {
    $scheme = new ConsumedBindingGuardScheme(['v1' => ''], activeKeyVersion: 'v1');

    expect(fn (): DerivedGuard => $scheme->active(CBGS_TC, CBGS_CAP, CBGS_FP))
        ->toThrow(MissingConsumedBindingGuardKey::class);
});

it('names the missing key version on the fail-closed exception', function (): void {
    try {
        (new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v9'))->active(CBGS_TC, CBGS_CAP, CBGS_FP);
        expect(false)->toBeTrue('expected a MissingConsumedBindingGuardKey to be thrown');
    } catch (MissingConsumedBindingGuardKey $exception) {
        expect($exception->keyVersion)->toBe('v9')
            ->and($exception->getMessage())->toContain('v9')
            ->and($exception)->toBeInstanceOf(RuntimeException::class); // a fail-closed signal that propagates
    }
});

it('is deterministic across calls', function (): void {
    $scheme = new ConsumedBindingGuardScheme(['v1' => 'secret-1'], activeKeyVersion: 'v1');

    expect($scheme->candidates(CBGS_TC, CBGS_CAP, CBGS_FP))->toBe($scheme->candidates(CBGS_TC, CBGS_CAP, CBGS_FP))
        ->and($scheme->active(CBGS_TC, CBGS_CAP, CBGS_FP)->digest)->toBe($scheme->active(CBGS_TC, CBGS_CAP, CBGS_FP)->digest);
});
