<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class CaseResult
{
    /**
     * @param  list<AssertionResult>  $assertions
     */
    public function __construct(
        public string $id,
        public string $version,
        public CasePurpose $purpose,
        public CaseStatus $status,
        public string $trustedSetupFingerprint,
        public string $untrustedInputFingerprint,
        public array $assertions,
        public ?ObservationEvidence $observation,
        public ?string $errorClass = null,
    ) {}
}
