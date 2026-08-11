<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

/**
 * @deprecated before 1.0: use EvidenceWriter and/or ProvenanceLedgerStore instead.
 *
 * @experimental The compatibility bridge may be removed before Verdict 1.0.
 */
interface EvidenceRecorder extends EvidenceWriter, ProvenanceLedgerStore {}
