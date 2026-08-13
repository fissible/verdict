<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Decisions\Disposition;

final class LiveToolCapture
{
    /** @var list<ToolObservation> */
    private array $calls = [];

    /** @var list<string> */
    private array $sideEffects = [];

    public function record(string $capability, string $argumentFingerprint, ?Disposition $disposition, bool $executed): void
    {
        $this->calls[] = new ToolObservation($capability, $argumentFingerprint, $disposition, $executed);
    }

    public function recordSideEffect(string $effect): void
    {
        $this->sideEffects[] = $effect;
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->sideEffects = [];
    }

    public function isEmpty(): bool
    {
        return $this->calls === [];
    }

    /** @return list<ToolObservation> */
    public function toolObservations(): array
    {
        return $this->calls;
    }

    /** @return list<string> */
    public function sideEffects(): array
    {
        return $this->sideEffects;
    }
}
