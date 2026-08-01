<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\ArgumentFingerprint;

it('produces a stable fingerprint independent of object key order', function (): void {
    $first = ArgumentFingerprint::make([
        'order' => ['id' => 1002, 'state' => 'paid'],
        'reason' => 'customer request',
    ]);

    $second = ArgumentFingerprint::make([
        'reason' => 'customer request',
        'order' => ['state' => 'paid', 'id' => 1002],
    ]);

    expect($first)
        ->toBe($second)
        ->toHaveLength(64);
});

it('keeps list order significant', function (): void {
    expect(ArgumentFingerprint::make(['items' => [1, 2]]))
        ->not->toBe(ArgumentFingerprint::make(['items' => [2, 1]]));
});
