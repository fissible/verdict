<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;

final readonly class ChainGapSummary
{
    public function __construct(
        public int $persistedCount,
        public ?DateTimeImmutable $latestMarkAt,
    ) {}

    public static function none(): self
    {
        return new self(0, null);
    }

    public function hasGaps(): bool
    {
        return $this->persistedCount > 0;
    }
}
