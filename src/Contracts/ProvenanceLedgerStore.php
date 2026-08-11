<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;

/** Reads the provenance and derivation ledger without requiring write capabilities. */
interface ProvenanceLedgerStore
{
    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array;

    /** @return list<ProvenanceDerivation> */
    public function derivationsFor(string $correlationId, string $childContentFingerprint): array;
}
