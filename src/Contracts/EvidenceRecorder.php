<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

/**
 * @deprecated before 1.0: depend on EvidenceWriter and/or ProvenanceLedgerStore instead.
 */
interface EvidenceRecorder extends EvidenceWriter, ProvenanceLedgerStore {}
