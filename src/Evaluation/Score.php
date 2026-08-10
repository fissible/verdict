<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class Score
{
    public function __construct(
        public int $passed,
        public int $failed,
        public int $errors,
        public int $pending,
    ) {}

    public function evaluated(): int
    {
        return $this->passed + $this->failed;
    }

    public function total(): int
    {
        return $this->evaluated() + $this->errors + $this->pending;
    }

    public function passRate(): ?float
    {
        $evaluated = $this->evaluated();

        return $evaluated === 0 ? null : $this->passed / $evaluated;
    }
}
