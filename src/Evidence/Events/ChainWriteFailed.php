<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence\Events;

final readonly class ChainWriteFailed
{
    /**
     * @param  'resolve_chain_id'|'append'  $phase  Which step failed. 'resolve_chain_id' means the
     *                                              chainIdUsing resolver itself threw before any write was attempted — $attempts is always 0
     *                                              in that case and must not be read as "0 of N append attempts exhausted."
     */
    public function __construct(
        public string $chainId,
        public ?string $correlationId,
        public string $recordType,
        public string $phase,
        public int $attempts,
        public string $message,
    ) {}
}
