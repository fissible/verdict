<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

final readonly class ReviewTransition
{
    private function __construct(
        public ReviewOutcome $outcome,
        public ?ReviewRequest $request,
    ) {}

    public static function to(ReviewOutcome $outcome, ?ReviewRequest $request = null): self
    {
        return new self($outcome, $request);
    }

    public function succeeded(): bool
    {
        return $this->outcome->succeeded();
    }
}
