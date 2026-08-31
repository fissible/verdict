<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\ProposalProvenance;

final readonly class ReviewRequest
{
    /**
     * @param  ?array<string, string|int>  $approvalContext  application-owned binding identifiers
     *                                                       (tenant, conversation, …) captured
     *                                                       verbatim from the ActionContext when
     *                                                       the request was issued. An empty array
     *                                                       means the application supplied none;
     *                                                       null means the request was issued
     *                                                       before Verdict captured it — "never
     *                                                       captured," mirroring $provenance.
     */
    public function __construct(
        public string $id,
        public string $capability,
        public string $bindingFingerprint,
        public ?array $approvalContext,
        public ?ProposalProvenance $provenance,
        public ?ApproverSummary $approverSummary,
        public ReviewStatus $status,
        public ?string $reason,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?string $resolvedBy,
        public ?DateTimeImmutable $resolvedAt,
        public ?DateTimeImmutable $consumedAt,
    ) {}

    /** @param  ?array<string, string|int>  $approvalContext */
    public static function pending(
        string $id,
        string $capability,
        string $bindingFingerprint,
        ?array $approvalContext,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?string $reason = null,
        ?ProposalProvenance $provenance = null,
        ?ApproverSummary $approverSummary = null,
    ): self {
        return new self(
            $id,
            $capability,
            $bindingFingerprint,
            $approvalContext,
            $provenance,
            $approverSummary,
            ReviewStatus::Pending,
            $reason,
            $createdAt,
            $expiresAt,
            null,
            null,
            null,
        );
    }

    public function isExpiredAt(DateTimeImmutable $time): bool
    {
        return $time >= $this->expiresAt;
    }
}
