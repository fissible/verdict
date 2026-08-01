<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class AssertionResult
{
    public function __construct(
        public string $assertion,
        public bool $passed,
        public ?string $message = null,
    ) {}
}
