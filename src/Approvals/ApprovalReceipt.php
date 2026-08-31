<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateTimeImmutable;
use Fissible\Verdict\Support\ApproverSummary;

final readonly class ApprovalReceipt
{
    /**
     * @param  ?ProposalProvenance  $provenance  what the approver is told about the proposal's
     *                                           origin, assembled when the receipt was issued.
     *                                           Null only for receipts issued before Verdict
     *                                           recorded it — "never captured," which is neither
     *                                           the ledger's Unknown nor configuration's Unreleased.
     * @param  ?array<string, string|int>  $approvalContext  application-owned binding identifiers
     *                                                       (tenant, conversation, …) captured
     *                                                       verbatim from the ActionContext when
     *                                                       the receipt was issued. An empty array
     *                                                       means the application supplied none;
     *                                                       null means the receipt was issued
     *                                                       before Verdict captured it — "never
     *                                                       captured," mirroring $provenance.
     */
    public function __construct(
        public string $id,
        public string $toolCallId,
        public string $capability,
        public string $bindingFingerprint,
        public ?ProposalProvenance $provenance,
        public ?array $approvalContext,
        public ApprovalReceiptStatus $status,
        public ?string $reason,
        public DateTimeImmutable $expiresAt,
        public ?string $approvedBy,
        public ?DateTimeImmutable $approvedAt,
        public ?string $rejectedBy,
        public ?DateTimeImmutable $rejectedAt,
        public ?DateTimeImmutable $consumedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?ApproverSummary $approverSummary = null,
        public ?ApproverSummaryRelease $approverSummaryRelease = null,
    ) {}

    public function isExpiredAt(DateTimeImmutable $time): bool
    {
        return $time >= $this->expiresAt;
    }
}
