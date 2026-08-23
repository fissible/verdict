<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\LiveEvaluationOverRestrictionGate;
use Fissible\Verdict\Evaluation\LiveEvaluationThresholdDisposition;
use Fissible\Verdict\Evaluation\OverRestrictionRate;

it('computes the over-restriction rate over evaluated trials, null when nothing was evaluated', function (): void {
    expect((new OverRestrictionRate('search', 4, 30))->rate())->toBe(4 / 30)
        ->and((new OverRestrictionRate('search', 0, 30))->rate())->toBe(0.0)
        ->and((new OverRestrictionRate('search', 0, 0))->rate())->toBeNull();
});

it('rejects an over-restricted count that exceeds the evaluated count', function (): void {
    new OverRestrictionRate('search', 5, 4);
})->throws(InvalidArgumentException::class);

it('meets the maximum at zero, below, and exactly at it, and misses above it', function (): void {
    $maximum = 0.2;

    expect((new OverRestrictionRate('search', 0, 30))->disposition($maximum))->toBe(LiveEvaluationThresholdDisposition::Met)
        ->and((new OverRestrictionRate('search', 3, 30))->disposition($maximum))->toBe(LiveEvaluationThresholdDisposition::Met)
        ->and((new OverRestrictionRate('search', 6, 30))->disposition($maximum))->toBe(LiveEvaluationThresholdDisposition::Met)
        ->and((new OverRestrictionRate('search', 7, 30))->disposition($maximum))->toBe(LiveEvaluationThresholdDisposition::NotMet);
});

it('is not evaluated when the case produced no evaluated trials', function (): void {
    expect((new OverRestrictionRate('search', 0, 0))->disposition(0.0))
        ->toBe(LiveEvaluationThresholdDisposition::NotEvaluated);
});

it('allows every rate under the permissive default maximum of 1.0', function (): void {
    expect((new OverRestrictionRate('search', 30, 30))->disposition(1.0))
        ->toBe(LiveEvaluationThresholdDisposition::Met);
});

it('aggregates to not met when any case misses, not evaluated when every case is, met otherwise', function (): void {
    $gate = static fn (array $cases): LiveEvaluationOverRestrictionGate => new LiveEvaluationOverRestrictionGate(0.2, $cases);

    expect($gate([
        'a' => new OverRestrictionRate('a', 0, 10),
        'b' => new OverRestrictionRate('b', 9, 10),
    ])->disposition())->toBe(LiveEvaluationThresholdDisposition::NotMet)
        ->and($gate([
            'a' => new OverRestrictionRate('a', 0, 0),
            'b' => new OverRestrictionRate('b', 0, 0),
        ])->disposition())->toBe(LiveEvaluationThresholdDisposition::NotEvaluated)
        ->and($gate([
            'a' => new OverRestrictionRate('a', 1, 10),
            'b' => new OverRestrictionRate('b', 0, 0),
        ])->disposition())->toBe(LiveEvaluationThresholdDisposition::Met);
});

it('rejects a maximum outside 0 to 1 and an empty case set', function (): void {
    expect(fn () => new LiveEvaluationOverRestrictionGate(1.5, ['a' => new OverRestrictionRate('a', 0, 1)]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LiveEvaluationOverRestrictionGate(0.5, []))
        ->toThrow(InvalidArgumentException::class);
});
