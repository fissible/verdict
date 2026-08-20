<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\ToolObservation;
use Fissible\Verdict\Evidence\ProvenanceEntry;

it('rejects a newline-suffixed digest in ProvenanceEntry::assertFingerprint', function (): void {
    $canonical = str_repeat('a', 64);

    ProvenanceEntry::assertFingerprint($canonical, 'content');

    expect(fn () => ProvenanceEntry::assertFingerprint($canonical."\n", 'content'))
        ->toThrow(InvalidArgumentException::class, 'The provenance content fingerprint must be a lowercase SHA-256 digest.');
});

it('rejects a newline-suffixed digest in Assertions fingerprint requirements', function (): void {
    $canonical = str_repeat('b', 64);

    Assertions::toolArgumentFingerprintIs('orders.view', $canonical);

    expect(fn () => Assertions::toolArgumentFingerprintIs('orders.view', $canonical."\n"))
        ->toThrow(InvalidArgumentException::class, 'A tool argument fingerprint assertion requires a SHA-256 fingerprint.');
});

it('rejects a newline-suffixed digest in the ToolObservation constructor', function (): void {
    $canonical = str_repeat('c', 64);

    new ToolObservation('orders.view', $canonical, null, false);

    expect(fn () => new ToolObservation('orders.view', $canonical."\n", null, false))
        ->toThrow(InvalidArgumentException::class, 'A tool observation requires a SHA-256 argument fingerprint.');
});
