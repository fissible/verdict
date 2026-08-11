<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Envelope\SignedEnvelope;

final class ChainCallCounter
{
    public int $appends = 0;
}

final readonly class FlakyChainStore implements ChainStore
{
    public function __construct(
        private ChainStore $inner,
        private int $failures,
        private ChainCallCounter $counter = new ChainCallCounter,
    ) {}

    public function counter(): ChainCallCounter
    {
        return $this->counter;
    }

    public function append(string $chainId, callable $buildAndSign): SignedEnvelope
    {
        $this->counter->appends++;

        if ($this->counter->appends <= $this->failures) {
            throw new ChainLockUnavailable($chainId);
        }

        return $this->inner->append($chainId, $buildAndSign);
    }

    public function tail(string $chainId): ?SignedEnvelope
    {
        return $this->inner->tail($chainId);
    }

    public function readRange(string $chainId, int $fromSeq, ?int $toSeq = null): iterable
    {
        return $this->inner->readRange($chainId, $fromSeq, $toSeq);
    }

    public function listChains(): iterable
    {
        return $this->inner->listChains();
    }

    public function exists(string $chainId): bool
    {
        return $this->inner->exists($chainId);
    }
}
