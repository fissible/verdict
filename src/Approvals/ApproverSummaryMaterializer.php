<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Support\ApproverSummary;

final readonly class ApproverSummaryMaterializer
{
    public const int MAX_CONTENT_BYTES = 2048;

    /**
     * @internal Resolve ApproverSummaryMaterializer from the container. This constructor is not
     *           part of the supported surface and may gain required parameters in any release.
     *           See docs/adr/0019-verdict-services-are-container-resolved.md.
     */
    public function __construct(private ContextReleaseManager $releases) {}

    public function materialize(?string $candidate): ApproverSummaryMaterialization
    {
        if ($candidate === null) {
            return new ApproverSummaryMaterialization(
                release: ApproverSummaryRelease::NotReleased,
                summary: null,
                diagnostic: ApproverSummaryDiagnostic::NoCandidate,
            );
        }

        if (trim($candidate) === ''
            || strlen($candidate) > self::MAX_CONTENT_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return new ApproverSummaryMaterialization(
                release: ApproverSummaryRelease::NotReleased,
                summary: null,
                diagnostic: ApproverSummaryDiagnostic::DisplayContractViolation,
            );
        }

        $release = $this->releases->release(
            payload: ['summary' => $candidate],
            source: ApproverAudience::source(),
            trust: Trust::Untrusted,
            dataClass: DataClass::Internal,
            paths: ['summary'],
            destination: ApproverAudience::destination(),
        );

        if (! $release->permitted) {
            return new ApproverSummaryMaterialization(
                release: ApproverSummaryRelease::ReleaseDenied,
                summary: null,
            );
        }

        return new ApproverSummaryMaterialization(
            release: ApproverSummaryRelease::Released,
            summary: new ApproverSummary($candidate, hash('sha256', $candidate)),
        );
    }
}
