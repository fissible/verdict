<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

/**
 * What an approval payload can say about where a proposal came from.
 *
 * Unknown is a reported state, not an omission: no derivation was declared, which means "not
 * observed or not declared," never "no influence occurred."
 * See docs/adr/0026-what-an-approver-is-shown.md §4.
 *
 * Unreleased is a claim about configuration rather than about the ledger, and is why the two are
 * separate cases: the application has registered no release policy for the approver route, so
 * Verdict is not in a position to say anything about this proposal's origin — which is not the
 * same as having looked and found nothing.
 */
enum ProvenanceDisclosure: string
{
    case Declared = 'declared';
    case Unknown = 'unknown';
    case Unreleased = 'unreleased';
}
