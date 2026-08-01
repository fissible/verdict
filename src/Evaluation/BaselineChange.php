<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class BaselineChange
{
    public function __construct(
        public string $caseId,
        public CasePurpose $purpose,
        public BaselineChangeKind $kind,
        public ?CaseStatus $baselineStatus,
        public ?CaseStatus $currentStatus,
    ) {}
}
