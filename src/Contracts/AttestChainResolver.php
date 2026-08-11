<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

interface AttestChainResolver
{
    /**
     * Return the chain id to write to for the write currently in progress.
     *
     * May throw. A thrown exception is not treated as a bug — it is handled the same
     * way as an exhausted chain-store write: a chain_gap marker is recorded, a
     * ChainWriteFailed event is dispatched with phase: 'resolve_chain_id' and
     * attempts: 0, and the caller is not blocked unless on_failure is 'throw'. See
     * AttestEvidenceRecorder::writeChained().
     */
    public function resolve(): string;
}
