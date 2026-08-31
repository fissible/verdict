<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateTimeImmutable;
use Fissible\Verdict\Support\ApproverSummary;

final readonly class ApprovalChallenge
{
    public function __construct(
        public string $receiptId,
        public string $toolCallId,
        public string $capability,
        public ?string $reason,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $issuedAt = new DateTimeImmutable,
        public ?ProposalProvenance $provenance = null,
        public ?ApproverSummary $approverSummary = null,
        public ?ApproverSummaryRelease $approverSummaryRelease = null,
    ) {}

    public static function fromReceipt(ApprovalReceipt $receipt): self
    {
        return new self(
            receiptId: $receipt->id,
            toolCallId: $receipt->toolCallId,
            capability: $receipt->capability,
            reason: $receipt->reason,
            expiresAt: $receipt->expiresAt,
            issuedAt: $receipt->createdAt,
            provenance: $receipt->provenance,
            approverSummary: $receipt->approverSummary,
            approverSummaryRelease: $receipt->approverSummaryRelease,
        );
    }
}
