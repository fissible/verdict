<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

final readonly class ApproverSummary
{
    public function __construct(
        public string $content,
        public string $fingerprint,
    ) {}
}
