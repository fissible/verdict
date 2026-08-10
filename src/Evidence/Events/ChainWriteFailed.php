<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence\Events;

final readonly class ChainWriteFailed
{
    public function __construct(
        public string $chainId,
        public ?string $correlationId,
        public string $recordType,
        public int $attempts,
        public string $message,
    ) {}
}
