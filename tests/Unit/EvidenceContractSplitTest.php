<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\ProvenanceLedgerStore;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;

it('keeps the mixed recorder as a pre-1.0 bridge while exposing narrow contracts', function (): void {
    foreach ([
        new InMemoryEvidenceRecorder,
        new NullEvidenceRecorder,
    ] as $adapter) {
        expect($adapter)
            ->toBeInstanceOf(EvidenceWriter::class)
            ->toBeInstanceOf(ProvenanceLedgerStore::class)
            ->toBeInstanceOf(EvidenceRecorder::class);
    }
});

it('allows write and query adapters to be implemented independently', function (): void {
    $writer = new class implements EvidenceWriter
    {
        public function record(DecisionEvidence $evidence): void {}

        public function recordRelease(ContextReleaseEvidence $evidence): void {}

        public function recordProvenance(ProvenanceEntry $entry): void {}

        public function recordDerivation(ProvenanceDerivation $derivation): void {}
    };
    $ledger = new class implements ProvenanceLedgerStore
    {
        public function provenanceFor(string $correlationId): array
        {
            return [];
        }

        public function derivationsFor(string $correlationId, string $childContentFingerprint): array
        {
            return [];
        }
    };

    expect($writer)->not->toBeInstanceOf(ProvenanceLedgerStore::class)
        ->and($ledger)->not->toBeInstanceOf(EvidenceWriter::class);
});
