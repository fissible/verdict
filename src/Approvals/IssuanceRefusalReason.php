<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

enum IssuanceRefusalReason: string
{
    case SummaryNotReleased = 'summary_not_released';
    case AttestNotConfigured = 'attest_not_configured';
    case AttestAppendFailed = 'attest_append_failed';
}
