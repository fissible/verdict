<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

/**
 * What an approval payload can say about where a proposal came from.
 *
 * Unknown is a reported state, not an omission: no derivation was declared, which means "not
 * observed or not declared," never "no influence occurred."
 * See docs/adr/0026-what-an-approver-is-shown.md §4.
 */
enum ProvenanceDisclosure: string
{
    case Declared = 'declared';
    case Unknown = 'unknown';
}
