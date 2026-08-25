<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateTimeImmutable;

/**
 * The observational read of one approval receipt (ADR 0031 §2). A DTO, never a row and never a
 * live model: every field is a fingerprint, an opaque identifier, a timestamp, or an
 * application-supplied scalar the application chose to bind. There is deliberately no `Expired`
 * status and no expiry computation here — expiry has no transition moment (ADR 0029 §1), so the
 * view reports the persisted status plus `expiresAt` and the consumer compares clocks. A decided
 * status (Approved/Rejected/Consumed) versus Pending-with-a-lapsed-deadline is exactly the
 * distinction a consumer needs to split "already decided" from "lapsed, undecided".
 *
 * `bindingFingerprint` is deliberately absent: safe but consumer-less (ADR 0031 §2); it joins as
 * a trailing optional addition when a consumer demonstrates a concrete need.
 */
final readonly class ApprovalStatusView
{
    /** @param  ?array<string, string|int>  $approvalContext */
    public function __construct(
        public string $receiptId,
        public string $toolCallId,
        public string $capability,
        public ApprovalReceiptStatus $status,
        public ?string $reason,
        public DateTimeImmutable $expiresAt,
        public ?string $approvedBy,
        public ?DateTimeImmutable $approvedAt,
        public ?string $rejectedBy,
        public ?DateTimeImmutable $rejectedAt,
        public ?DateTimeImmutable $consumedAt,
        public DateTimeImmutable $createdAt,
        public ?array $approvalContext,
    ) {}

    public static function fromReceipt(ApprovalReceipt $receipt): self
    {
        return new self(
            receiptId: $receipt->id,
            toolCallId: $receipt->toolCallId,
            capability: $receipt->capability,
            status: $receipt->status,
            reason: $receipt->reason,
            expiresAt: $receipt->expiresAt,
            approvedBy: $receipt->approvedBy,
            approvedAt: $receipt->approvedAt,
            rejectedBy: $receipt->rejectedBy,
            rejectedAt: $receipt->rejectedAt,
            consumedAt: $receipt->consumedAt,
            createdAt: $receipt->createdAt,
            approvalContext: $receipt->approvalContext,
        );
    }
}
