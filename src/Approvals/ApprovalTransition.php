<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

final readonly class ApprovalTransition
{
    private function __construct(
        public ApprovalOutcome $outcome,
        public ?ApprovalReceipt $receipt,
        public ?IssuanceRefusalReason $refusalReason,
    ) {}

    public static function to(
        ApprovalOutcome $outcome,
        ?ApprovalReceipt $receipt = null,
        ?IssuanceRefusalReason $refusalReason = null,
    ): self {
        return new self($outcome, $receipt, $refusalReason);
    }

    public function succeeded(): bool
    {
        return $this->outcome->succeeded();
    }
}
