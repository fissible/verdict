<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Support\ApproverSummary;

final readonly class ApproverSummaryMaterialization
{
    public function __construct(
        public ApproverSummaryRelease $release,
        public ?ApproverSummary $summary,
        public ?ApproverSummaryDiagnostic $diagnostic = null,
    ) {}
}
