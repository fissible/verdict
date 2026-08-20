<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Decisions\Disposition;
use InvalidArgumentException;

final readonly class ToolObservation
{
    public function __construct(
        public string $capability,
        public string $argumentFingerprint,
        public ?Disposition $disposition,
        public bool $executed,
    ) {
        if (trim($this->capability) === '') {
            throw new InvalidArgumentException('A tool observation must name a capability.');
        }

        if (preg_match('/^[a-f0-9]{64}\z/', $this->argumentFingerprint) !== 1) {
            throw new InvalidArgumentException('A tool observation requires a SHA-256 argument fingerprint.');
        }
    }
}
