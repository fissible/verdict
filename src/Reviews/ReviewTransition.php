<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use Fissible\Verdict\Approvals\IssuanceRefusalReason;

final readonly class ReviewTransition
{
    private function __construct(
        public ReviewOutcome $outcome,
        public ?ReviewRequest $request,
        public ?IssuanceRefusalReason $refusalReason,
    ) {}

    public static function to(
        ReviewOutcome $outcome,
        ?ReviewRequest $request = null,
        ?IssuanceRefusalReason $refusalReason = null,
    ): self {
        return new self($outcome, $request, $refusalReason);
    }

    public function succeeded(): bool
    {
        return $this->outcome->succeeded();
    }
}
