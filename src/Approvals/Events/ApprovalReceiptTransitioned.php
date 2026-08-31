<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals\Events;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;

/**
 * An approval receipt was issued or moved to a new persisted status.
 *
 * This event is a context release under ADR 0008. It carries only identity, resulting status,
 * and transition time; a listener that needs more must read it through ApprovalStatusReader::statusFor().
 */
final readonly class ApprovalReceiptTransitioned
{
    public function __construct(
        public string $receiptId,
        public string $toolCallId,
        public string $capability,
        public ApprovalReceiptStatus $status,
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * Chooses this event's field set in one place, so ADR 0008's payload decision stays out of
     * individual dispatch sites.
     */
    public static function from(
        ApprovalReceipt $receipt,
        ApprovalReceiptStatus $status,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self(
            receiptId: $receipt->id,
            toolCallId: $receipt->toolCallId,
            capability: $receipt->capability,
            status: $status,
            occurredAt: $occurredAt,
        );
    }
}
