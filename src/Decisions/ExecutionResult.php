<?php

declare(strict_types=1);

namespace Fissible\Verdict\Decisions;

final readonly class ExecutionResult
{
    private function __construct(
        public Evaluation $evaluation,
        public bool $executed,
        public mixed $output,
    ) {}

    public static function denied(Evaluation $evaluation): self
    {
        return new self($evaluation, false, null);
    }

    public static function executed(Evaluation $evaluation, mixed $output): self
    {
        return new self($evaluation, true, $output);
    }
}
