<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

/**
 * One assertion-only checkpoint over a refreshed execution target. Resource content never leaves
 * the capture; only its digest and the fields needed to pair two endpoint observations do.
 *
 * @experimental Part of the evaluation surface; may change before Verdict 1.0.
 */
final readonly class ResourceObservation
{
    public function __construct(
        public string $checkpoint,
        public string $resourceIdentity,
        public string $projection,
        public string $digest,
        public int $occurrence,
        public int $executionSequence,
    ) {
        if (trim($this->checkpoint) === '') {
            throw new InvalidArgumentException('A resource observation must name a checkpoint.');
        }

        if (! ResourceIdentity::isIdentity($this->resourceIdentity)) {
            throw new InvalidArgumentException('A resource observation requires a '.ResourceIdentity::SCHEME.'-tagged identity.');
        }

        if (trim($this->projection) === '') {
            throw new InvalidArgumentException('A resource observation must name a projection contract.');
        }

        if (! ResourceDigest::isDigest($this->digest)) {
            throw new InvalidArgumentException('A resource observation requires a '.ResourceDigest::SCHEME.'-tagged digest.');
        }

        if ($this->occurrence < 1 || $this->executionSequence < 1) {
            throw new InvalidArgumentException('A resource observation requires positive occurrence and execution sequence values.');
        }
    }
}
