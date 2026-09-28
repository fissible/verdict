<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evidence\ApprovalRefusalEvidence;
use Throwable;

/**
 * Opt-in seam for recording a REFUSED issuance as refusal-operation evidence (ADR 0039, pinned
 * regressions #10/#15). Deliberately not part of the Stable {@see EvidenceWriter} surface — its
 * ~15 implementors are unaffected. A recorder that supports refusal evidence implements this
 * interface; the manager only records through a writer that opts in, and leaves every other
 * writer untouched on the refusal path.
 */
interface RecordsApprovalRefusals
{
    /**
     * Record a refused issuance, deduplicated per binding digest with an attempt count so a replay
     * flood cannot grow evidence without bound.
     *
     * @throws Throwable on backend failure — the manager swallows it (dispatching EvidenceWriteFailed)
     *                   so a failing writer never blocks the caller.
     */
    public function recordApprovalRefusal(ApprovalRefusalEvidence $evidence): void;
}
