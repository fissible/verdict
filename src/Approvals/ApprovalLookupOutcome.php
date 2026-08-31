<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

/**
 * Which of the three states a tool-call read landed in (#425). A tool-call id is a provider
 * identifier, not a Verdict key — the receipts table is unique on
 * (tool_call_id, capability, binding_fingerprint) — so multiplicity is a legal, real event and
 * must be distinguishable from absence rather than collapsed into the same nothing.
 */
enum ApprovalLookupOutcome: string
{
    case Absent = 'absent';
    case Single = 'single';
    case Multiple = 'multiple';
}
