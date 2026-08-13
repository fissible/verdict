<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class LiveEvaluationCaseResult
{
    /**
     * @param  array<string,int>  $errorBreakdown
     */
    public function __construct(
        public string $id,
        public string $version,
        public CasePurpose $purpose,
        public string $trustedSetupFingerprint,
        public string $untrustedInputFingerprint,
        public Score $score,
        public array $errorBreakdown = [],
    ) {}
}
