<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

use Fissible\Verdict\Evidence\ContextReleaseEvidence;

final readonly class ContextReleaseResult
{
    /** @param array<string, mixed> $payload */
    private function __construct(
        public bool $permitted,
        public array $payload,
        public ContextReleaseEvidence $evidence,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function permitted(array $payload, ContextReleaseEvidence $evidence): self
    {
        return new self(true, $payload, $evidence);
    }

    public static function denied(ContextReleaseEvidence $evidence): self
    {
        return new self(false, [], $evidence);
    }
}
