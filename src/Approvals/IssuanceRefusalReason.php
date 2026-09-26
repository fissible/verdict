<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

enum IssuanceRefusalReason: string
{
    case SummaryNotReleased = 'summary_not_released';
    case AttestNotConfigured = 'attest_not_configured';
    case AttestAppendFailed = 'attest_append_failed';
    case PreviouslyConsumed = 'previously_consumed';

    /**
     * A strict (attested) issuance was requested against a store that does not implement
     * IssuesAdmittedReceipts, so the attest cannot be ordered after the admission check inside the
     * admission-locked transaction (ADR 0039). Rather than revert to the buggy attest-before-issue
     * order, the manager fails such an issuance closed.
     */
    case AttestOrderingUnsupported = 'attest_ordering_unsupported';
}
